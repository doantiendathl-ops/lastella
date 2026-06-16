<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class FolioService
{
    public function createFolioForBooking(Booking $booking): Folio
    {
        /** @var Folio $folio */
        $folio = Folio::create([
            'booking_id'   => $booking->id,
            'folio_number' => $this->generateFolioNumber(),
            'status'       => FolioStatus::Open,
            'created_by'   => Auth::id(),
        ]);

        return $folio;
    }

    public function addCharge(Folio $folio, array $data): FolioEntry
    {
        if ($folio->status !== FolioStatus::Open) {
            throw ValidationException::withMessages([
                'folio' => 'Folio đã đóng, không thể thêm phí.',
            ]);
        }

        $data['posted_by']  = $data['posted_by'] ?? Auth::id();
        $data['entry_date'] = $data['entry_date'] ?? today();

        /** @var FolioEntry $entry */
        $entry = $folio->folioEntries()->create($data);

        return $entry;
    }

    public function voidEntry(FolioEntry $entry, string $reason): void
    {
        if ($entry->voided_at !== null) {
            throw ValidationException::withMessages([
                'entry' => 'Phí này đã được hủy trước đó.',
            ]);
        }

        $entry->update([
            'voided_at'   => now(),
            'voided_by'   => Auth::id(),
            'void_reason' => $reason,
        ]);
    }

    public function autoPostRoomCharge(Booking $booking): ?FolioEntry
    {
        $folio = $booking->folio;

        if ($folio === null) {
            return null;
        }

        if ($folio->folioEntries()
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->exists()) {
            return null;
        }

        $booking->loadMissing('bookingRequirements');

        $amount = (float) $booking->bookingRequirements->sum(
            fn ($r) => (float) $r->room_price * (int) $r->quantity,
        );

        if ($amount <= 0.0) {
            return null;
        }

        return $this->addCharge($folio, [
            'charge_type' => ChargeType::Room,
            'description' => 'Tiền phòng',
            'quantity'    => 1,
            'unit_price'  => $amount,
            'amount'      => $amount,
            'entry_date'  => today(),
        ]);
    }

    public function getFolioTotal(Booking $booking): float
    {
        $folio = $booking->folio;

        if ($folio === null) {
            return 0.0;
        }

        return (float) $folio->folioEntries()->whereNull('voided_at')->sum('amount');
    }

    public function closeFolio(Folio $folio): void
    {
        $folio->update([
            'status'    => FolioStatus::Closed,
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);
    }

    public function reopenFolio(Folio $folio): void
    {
        $folio->update([
            'status'    => FolioStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }

    private function generateFolioNumber(): string
    {
        $prefix = 'FLO-' . now()->format('Ymd') . '-';

        do {
            $last   = Folio::where('folio_number', 'like', $prefix . '%')
                ->orderBy('folio_number', 'desc')
                ->first();
            $seq    = $last ? ((int) substr($last->folio_number, -4) + 1) : 1;
            $number = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        } while (Folio::where('folio_number', $number)->exists());

        return $number;
    }
}
