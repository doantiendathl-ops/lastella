<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\StoreFolioEntryRequest;
use App\Http\Requests\Folio\VoidFolioEntryRequest;
use App\Models\Booking;
use App\Models\FolioEntry;
use App\Services\FolioService;
use Illuminate\Http\RedirectResponse;

class FolioEntryController extends Controller
{
    public function __construct(private readonly FolioService $folios)
    {
    }

    public function store(StoreFolioEntryRequest $request, Booking $booking): RedirectResponse
    {
        $folio = $booking->folio;
        abort_if($folio === null, 404);
        $this->authorize('create', [FolioEntry::class, $folio]);

        $this->folios->addCharge($folio, $request->validated());

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])
            ->with('success', 'Đã thêm phí.');
    }

    public function void(VoidFolioEntryRequest $request, Booking $booking, FolioEntry $entry): RedirectResponse
    {
        $this->authorize('void', $entry);
        abort_unless($entry->folio?->booking_id === $booking->id, 403);

        $this->folios->voidEntry($entry, $request->validated('void_reason'));

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])
            ->with('success', 'Đã hủy phí.');
    }
}
