<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Enums\CheckoutInspectionStatus;
use App\Enums\StayEventType;
use App\Models\CheckoutInspection;
use App\Models\CheckoutInspectionItem;
use App\Models\Folio;
use App\Models\ProductService;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutInspectionService
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly StayEventService $stayEvents,
    ) {
    }

    /**
     * Idempotent: a stay has at most one inspection (unique(stay_id)).
     * Returns the existing draft/completed inspection if one already exists.
     */
    public function getOrCreateDraft(Stay $stay, User $actor): CheckoutInspection
    {
        return DB::transaction(function () use ($stay, $actor): CheckoutInspection {
            $lockedStay = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();

            $existing = CheckoutInspection::where('stay_id', $lockedStay->id)->first();
            if ($existing !== null) {
                return $existing->load('items');
            }

            if ($lockedStay->status !== \App\Enums\StayStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'stay' => 'Chỉ có thể kiểm đồ cho phòng đang có khách lưu trú.',
                ]);
            }

            $lockedStay->loadMissing('booking.folio');

            /** @var CheckoutInspection $inspection */
            $inspection = CheckoutInspection::create([
                'booking_id' => $lockedStay->booking_id,
                'stay_id' => $lockedStay->id,
                'room_id' => $lockedStay->room_id,
                'folio_id' => $lockedStay->booking?->folio?->id,
                'status' => CheckoutInspectionStatus::Draft,
                'total_amount' => 0,
                'created_by' => $actor->id,
            ]);

            return $inspection->load('items');
        });
    }

    /**
     * @param array<int, array{product_service_id:int, chargeable_quantity:int, note?:string|null}> $itemsInput
     */
    public function saveDraft(CheckoutInspection $inspection, array $itemsInput, ?string $note, User $actor): CheckoutInspection
    {
        return DB::transaction(function () use ($inspection, $itemsInput, $note): CheckoutInspection {
            $locked = CheckoutInspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($locked);

            $builtItems = $this->buildItemRows($itemsInput);

            $locked->items()->delete();
            foreach ($builtItems as $row) {
                $locked->items()->create($row);
            }

            $locked->update([
                'note' => $note,
                'total_amount' => array_sum(array_column($builtItems, 'line_total')),
            ]);

            return $locked->refresh()->load('items');
        });
    }

    /**
     * Finalizes the inspection's canonical quantities — Draft → Completed.
     *
     * Pre-Commit Critical Safety Closure (Mục I–VII): this NO LONGER posts to
     * the Folio. Doing so here (as the previous round did) meant a Completed-
     * but-not-yet-checked-out inspection had already created a system
     * FolioEntry (non-null posting_key), and the only way to let staff correct
     * it before checkout was to void/rewrite that system entry directly —
     * exactly the invariant FolioService::voidEntry()'s ADR-50 guard exists to
     * prevent (see the removed voidInspectionFolioEntries()). The fix is not a
     * bypass of that guard; it is to never post before the guard would apply.
     *
     * `total_amount` here is a PROJECTION only — the guest-facing amount this
     * inspection currently implies, freely correctable up until checkout.
     * The actual FolioEntry is posted exactly once, at the existing final
     * posting point for a Stay's charges: StayService::checkOut(), via
     * postCompletedChargesAtCheckout() — the same pattern already used for
     * LateCheckoutFeePostingJob (an event-triggered, checkout-time posting).
     *
     * Idempotent: calling again on an already-Completed inspection is a no-op
     * (handles double submit / request retry) — the caller should use
     * editCompleted() to actually change quantities on a Completed sheet.
     *
     * @param array<int, array{product_service_id:int, chargeable_quantity:int, note?:string|null}> $itemsInput
     */
    public function complete(CheckoutInspection $inspection, array $itemsInput, ?string $note, User $actor): CheckoutInspection
    {
        return DB::transaction(function () use ($inspection, $itemsInput, $note, $actor): CheckoutInspection {
            // Row lock on the inspection serializes concurrent complete() calls for the
            // same sheet (double click / retry) — the second caller blocks here until the
            // first commits, then observes status === Completed below and returns early.
            $locked = CheckoutInspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === CheckoutInspectionStatus::Completed) {
                return $locked->refresh()->load('items');
            }

            $this->assertEditable($locked);

            $builtItems = $this->buildItemRows($itemsInput);

            $locked->items()->delete();
            $createdItems = [];
            foreach ($builtItems as $row) {
                $createdItems[] = $locked->items()->create($row);
            }

            $totalAmount = array_sum(array_column($builtItems, 'line_total'));

            $locked->update([
                'status' => CheckoutInspectionStatus::Completed,
                'note' => $note,
                'total_amount' => $totalAmount,
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ]);

            $this->stayEvents->record($locked->stay, StayEventType::InspectionCompleted, $actor, [
                'version' => 1,
                'inspection_id' => $locked->id,
                'total_amount' => (float) $totalAmount,
                'item_count' => count($createdItems),
            ]);

            return $locked->refresh()->load('items');
        });
    }

    /**
     * Inspection Financial Correction (Mục IV/VII/VIII/IX), re-architected by
     * the Pre-Commit Critical Safety Closure: a stay whose inspection is
     * already Completed but is NOT yet checked out may still be corrected —
     * nhập sai quantity, chọn nhầm sản phẩm, kiểm lại phòng phát hiện thêm
     * hàng. Reflects the LATEST data only (never the sum of old+new).
     *
     * Mechanism, now trivial and invariant-safe: nothing is posted to Folio
     * before checkout (see complete()), so there is no system FolioEntry to
     * void or rewrite here at all — this simply replaces the
     * CheckoutInspectionItem rows and recomputes the projected total. Once
     * checkout actually posts the charge (postCompletedChargesAtCheckout()),
     * `isPosted()` becomes true and this method refuses to touch quantities
     * ever again (defensive guard — under normal flow this state is
     * unreachable pre-checkout, since posting and the checkout status flip
     * happen atomically in the same transaction).
     *
     * Idempotent: if the resubmitted items are identical (same product,
     * same chargeable_quantity, same line_total) to what is already saved,
     * this is a no-op — no audit event.
     *
     * @param array<int, array{product_service_id:int, chargeable_quantity:int, note?:string|null}> $itemsInput
     */
    public function editCompleted(CheckoutInspection $inspection, array $itemsInput, ?string $note, User $actor): CheckoutInspection
    {
        return DB::transaction(function () use ($inspection, $itemsInput, $note, $actor): CheckoutInspection {
            $locked = CheckoutInspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CheckoutInspectionStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ áp dụng cho phiếu kiểm đồ đã hoàn tất. Dùng lưu nháp/hoàn tất cho phiếu đang kiểm.',
                ]);
            }

            $locked->loadMissing('stay', 'items');

            if ($locked->stay?->actual_checkout_at !== null) {
                throw ValidationException::withMessages([
                    'stay' => 'Phòng đã trả. Dữ liệu kiểm đồ đã được chốt.',
                ]);
            }

            // Hard Rule (Pre-Commit Safety Closure Mục III/V): once a charge is an
            // actual system FolioEntry, this method must NEVER touch it — no void,
            // no rewrite, no bypass of FolioService's ADR-50 guard. Under normal
            // flow isPosted() is always false here (posting only happens inside
            // checkOut(), atomically with the actual_checkout_at check above), so
            // this is a defensive backstop against a future regression, not the
            // normal rejection path.
            if ($locked->isPosted()) {
                throw ValidationException::withMessages([
                    'inspection' => 'Phiếu kiểm đồ đã được ghi nhận vào Folio. Không thể sửa số lượng.',
                ]);
            }

            $builtItems = $this->buildItemRows($itemsInput);
            $newTotal = array_sum(array_column($builtItems, 'line_total'));

            if ($this->itemsUnchanged($locked, $builtItems, $newTotal)) {
                return $locked->refresh()->load('items');
            }

            $oldItemsSnapshot = $locked->items->map(fn (CheckoutInspectionItem $i): array => [
                'product_service_id' => $i->product_service_id,
                'product_name' => $i->product_name_snapshot,
                'chargeable_quantity' => $i->chargeable_quantity,
                'line_total' => (float) $i->line_total,
            ])->values()->all();
            $oldTotal = (float) $locked->total_amount;

            $locked->items()->delete();
            $createdItems = [];
            foreach ($builtItems as $row) {
                $createdItems[] = $locked->items()->create($row);
            }

            $locked->update([
                'note' => $note,
                'total_amount' => $newTotal,
            ]);

            $newItemsSnapshot = collect($createdItems)->map(fn (CheckoutInspectionItem $i): array => [
                'product_service_id' => $i->product_service_id,
                'product_name' => $i->product_name_snapshot,
                'chargeable_quantity' => $i->chargeable_quantity,
                'line_total' => (float) $i->line_total,
            ])->values()->all();

            $this->stayEvents->record($locked->stay, StayEventType::InspectionEdited, $actor, [
                'version' => 1,
                'inspection_id' => $locked->id,
                'old_total_amount' => $oldTotal,
                'new_total_amount' => (float) $newTotal,
                'old_items' => $oldItemsSnapshot,
                'new_items' => $newItemsSnapshot,
            ]);

            return $locked->refresh()->load('items');
        });
    }

    /**
     * Called exactly once, at the moment of physical checkout
     * (StayService::checkOut(), immediately after the LateCheckoutFeePostingJob
     * call — same "final posting point" pattern). Posts the Completed
     * inspection's current (possibly staff-corrected) items to the Folio as
     * real system FolioEntry rows and marks the inspection posted. A Draft
     * (never-Completed) inspection posts nothing — same as skipping.
     * Idempotent via isPosted(): calling this twice for the same stay/
     * inspection is a no-op the second time.
     */
    public function postCompletedChargesAtCheckout(Stay $stay, ?User $actor): void
    {
        $inspection = CheckoutInspection::where('stay_id', $stay->id)
            ->where('status', CheckoutInspectionStatus::Completed)
            ->lockForUpdate()
            ->first();

        if ($inspection === null || $inspection->isPosted()) {
            return;
        }

        $inspection->loadMissing('items', 'booking.folio');
        $folio = $inspection->booking?->folio;

        if ($folio === null) {
            return;
        }

        $this->postChargesToFolio($inspection, $folio, $inspection->items->all(), $actor);

        $inspection->update([
            'folio_id' => $folio->id,
            'posted_at' => now(),
            'posting_batch_key' => 'CKI_' . $inspection->id,
        ]);
    }

    /**
     * Mục V/VI: whether a Completed inspection may still be corrected — true
     * only before the stay checks out AND before its charge has actually been
     * posted (the two happen atomically together in checkOut(), so in normal
     * flow they are equivalent; isPosted() is the defensive, always-correct
     * source of truth). After checkout the financial history is locked for
     * everyone, including ADMIN (no role bypass) — a genuine post-checkout
     * correction must go through the existing Folio void/manual-entry tools
     * directly, not this method.
     */
    public function canEditCompleted(CheckoutInspection $inspection): bool
    {
        $inspection->loadMissing('stay');

        return $inspection->status === CheckoutInspectionStatus::Completed
            && $inspection->stay?->actual_checkout_at === null
            && ! $inspection->isPosted();
    }

    /**
     * @param array<int, array<string, mixed>> $builtItems
     */
    private function itemsUnchanged(CheckoutInspection $inspection, array $builtItems, string $newTotal): bool
    {
        if (bccomp((string) $inspection->total_amount, $newTotal, 2) !== 0) {
            return false;
        }

        $existing = $inspection->items
            ->map(fn (CheckoutInspectionItem $i): array => [$i->product_service_id, $i->chargeable_quantity, (string) $i->line_total])
            ->sort()
            ->values()
            ->all();

        $incoming = collect($builtItems)
            ->map(fn (array $r): array => [$r['product_service_id'], $r['chargeable_quantity'], $r['line_total']])
            ->sort()
            ->values()
            ->all();

        return $existing === $incoming;
    }

    /**
     * @param array<int, CheckoutInspectionItem> $items
     */
    private function postChargesToFolio(CheckoutInspection $inspection, Folio $folio, array $items, ?User $actor): void
    {
        foreach ($items as $item) {
            if ($item->chargeable_quantity <= 0 || bccomp((string) $item->line_total, '0.00', 2) <= 0) {
                continue;
            }

            $chargeType = $item->productService?->resolveChargeType() ?? ChargeType::Other;

            $this->folioService->addCharge($folio, [
                'stay_id' => $inspection->stay_id,
                'posting_key' => 'CHECKOUT_INSPECTION_' . $inspection->id . '_' . $item->id,
                'posting_source' => 'CHECKOUT_INSPECTION',
                'charge_type' => $chargeType->value,
                'description' => "Kiểm đồ trả phòng: {$item->product_name_snapshot} x{$item->chargeable_quantity}",
                'quantity' => $item->chargeable_quantity,
                'unit_price' => $item->unit_price_snapshot,
                // Nullable: postCompletedChargesAtCheckout() may run with no
                // authenticated actor (e.g. a system-triggered checkout) —
                // FolioService::addCharge() already falls back to Auth::id()
                // (also nullable) when posted_by is omitted, same convention
                // PostingContext::$postedBy uses everywhere else in Posting/.
                'posted_by' => $actor?->id,
            ]);
        }
    }

    /**
     * Complimentary/Chargeable Separation (Mục X-XXI): the two are
     * completely independent — CHARGE = chargeable_quantity × unit_price,
     * never `consumed - complimentary`. `chargeable_quantity` is a DIRECT
     * user input (defaults to 0 client-side for a new row); `free_quantity`
     * is stored purely as an informational snapshot of the complimentary
     * standard shown at entry time (for room-capacity-scaled products like
     * water, the frontend resolves this from RoomType.standard_adults —
     * this service only stores whatever it's given) and never enters the
     * formula. `actual_quantity` mirrors `chargeable_quantity` — the column
     * remains for schema compatibility but is no longer independently
     * authoritative; nothing reads it for billing.
     *
     * @param array<int, array{product_service_id:int, chargeable_quantity:int, complimentary_quantity?:int|null, note?:string|null}> $itemsInput
     * @return array<int, array<string, mixed>>
     */
    private function buildItemRows(array $itemsInput): array
    {
        if (count($itemsInput) === 0) {
            return [];
        }

        $productIds = array_column($itemsInput, 'product_service_id');
        $products = ProductService::usableInCheckoutInspection()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($itemsInput as $input) {
            /** @var ProductService|null $product */
            $product = $products->get($input['product_service_id']);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'items' => "Sản phẩm/dịch vụ không hợp lệ hoặc đã ngừng sử dụng (ID {$input['product_service_id']}).",
                ]);
            }

            $chargeableQuantity = max(0, (int) $input['chargeable_quantity']);
            $complimentarySnapshot = array_key_exists('complimentary_quantity', $input) && $input['complimentary_quantity'] !== null
                ? max(0, (int) $input['complimentary_quantity'])
                : $product->free_quantity_default;

            $rows[] = [
                'product_service_id' => $product->id,
                'product_code_snapshot' => $product->code,
                'product_name_snapshot' => $product->name,
                'unit_snapshot' => $product->unit,
                'unit_price_snapshot' => $product->price,
                'free_quantity' => $complimentarySnapshot,
                'actual_quantity' => $chargeableQuantity,
                'chargeable_quantity' => $chargeableQuantity,
                'line_total' => bcmul((string) $chargeableQuantity, (string) $product->price, 2),
                'note' => $input['note'] ?? null,
            ];
        }

        return $rows;
    }

    private function assertEditable(CheckoutInspection $inspection): void
    {
        if ($inspection->status !== CheckoutInspectionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Phiếu kiểm đồ đã hoàn tất, không thể sửa trực tiếp. Cần quy trình điều chỉnh (hủy/tạo dòng phí mới).',
            ]);
        }
    }
}
