<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServicePriceRequest;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 5) — a price
 * change always INSERTS a new row, never updates one in place, so old
 * transactions' price snapshots (BookingService.suggested_price/actual_price)
 * stay meaningful forever. Mirrors ServicePackageRateController exactly.
 */
class ServicePriceController extends Controller
{
    public function store(StoreServicePriceRequest $request, Service $service): RedirectResponse
    {
        $this->authorize('manageRates', $service);

        $service->prices()->create([
            ...$request->validated(),
            'created_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.services.index')
            ->with('success', 'Đã thêm mức giá mới.');
    }

    public function toggle(Service $service, ServicePrice $price): RedirectResponse
    {
        $this->authorize('manageRates', $service);

        abort_unless($price->service_id === $service->id, 404);

        $price->update(['is_active' => ! $price->is_active]);

        $message = $price->is_active ? 'Đã kích hoạt mức giá.' : 'Đã ngừng mức giá.';

        return redirect()
            ->route('admin.services.index')
            ->with('success', $message);
    }
}
