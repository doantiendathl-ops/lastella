<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\FolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolioController extends Controller
{
    public function __construct(private readonly FolioService $folios)
    {
    }

    public function close(Request $request, Booking $booking): RedirectResponse
    {
        $folio = $booking->folio;
        abort_if($folio === null, 404);
        $this->authorize('close', $folio);

        $this->folios->closeFolio($folio);

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])
            ->with('success', 'Đã đóng folio.');
    }

    public function reopen(Request $request, Booking $booking): RedirectResponse
    {
        $folio = $booking->folio;
        abort_if($folio === null, 404);
        $this->authorize('reopen', $folio);

        $this->folios->reopenFolio($folio);

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])
            ->with('success', 'Đã mở lại folio.');
    }
}
