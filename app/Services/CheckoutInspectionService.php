<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Enums\CheckoutInspectionStatus;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
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

            if ($lockedStay->status !== StayStatus::CheckedIn) {
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
     * @param array<int, array{product_service_id:int, actual_quantity:int, chargeable_quantity_override?:int|null, note?:string|null}> $itemsInput
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
     * Finalizes the inspection and posts chargeable lines to the folio.
     * Idempotent: calling again on an already-completed+posted inspection is a no-op
     * (handles double submit / request retry) — see ADR note on posting_batch_key below.
     *
     * @param array<int, array{product_service_id:int, actual_quantity:int, chargeable_quantity_override?:int|null, note?:string|null}> $itemsInput
     */
    public function complete(CheckoutInspection $inspection, array $itemsInput, ?string $note, User $actor): CheckoutInspection
    {
        return DB::transaction(function () use ($inspection, $itemsInput, $note, $actor): CheckoutInspection {
            // Row lock on the inspection serializes concurrent complete() calls for the
            // same sheet (double click / retry) — the second caller blocks here until the
            // first commits, then observes isPosted() === true below and returns early.
            $locked = CheckoutInspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
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

            $locked->loadMissing('booking.folio');
            $folio = $locked->booking?->folio;

            if ($folio === null) {
                throw ValidationException::withMessages([
                    'folio' => 'Booking chưa có folio để ghi nhận phí kiểm đồ.',
                ]);
            }

            $this->postChargesToFolio($locked, $folio, $createdItems, $actor);

            $locked->update([
                'status' => CheckoutInspectionStatus::Completed,
                'note' => $note,
                'total_amount' => $totalAmount,
                'folio_id' => $folio->id,
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'posted_at' => now(),
                'posting_batch_key' => 'CKI_' . $locked->id,
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
     * @param array<int, CheckoutInspectionItem> $items
     */
    private function postChargesToFolio(CheckoutInspection $inspection, Folio $folio, array $items, User $actor): void
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
                'posted_by' => $actor->id,
            ]);
        }
    }

    /**
     * @param array<int, array{product_service_id:int, actual_quantity:int, chargeable_quantity_override?:int|null, note?:string|null}> $itemsInput
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

            $actualQuantity = max(0, (int) $input['actual_quantity']);
            $freeQuantity = $product->free_quantity_default;
            $defaultChargeable = max($actualQuantity - $freeQuantity, 0);
            $chargeableQuantity = array_key_exists('chargeable_quantity_override', $input) && $input['chargeable_quantity_override'] !== null
                ? max(0, (int) $input['chargeable_quantity_override'])
                : $defaultChargeable;

            $rows[] = [
                'product_service_id' => $product->id,
                'product_code_snapshot' => $product->code,
                'product_name_snapshot' => $product->name,
                'unit_snapshot' => $product->unit,
                'unit_price_snapshot' => $product->price,
                'free_quantity' => $freeQuantity,
                'actual_quantity' => $actualQuantity,
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
