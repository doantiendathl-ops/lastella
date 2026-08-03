<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServicePackageRateRequest;
use App\Models\ServicePackage;
use App\Models\ServicePackageRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ServicePackageRateController extends Controller
{
    public function store(StoreServicePackageRateRequest $request, ServicePackage $servicePackage): RedirectResponse
    {
        $this->authorize('manageRates', $servicePackage);

        $servicePackage->rates()->create([
            ...$request->validated(),
            'created_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.service-packages.history', $servicePackage)
            ->with('success', 'Đã thêm mức giá mới.');
    }

    public function toggle(ServicePackage $servicePackage, ServicePackageRate $rate): RedirectResponse
    {
        $this->authorize('manageRates', $servicePackage);

        abort_unless($rate->service_package_id === $servicePackage->id, 404);

        $rate->update(['is_active' => ! $rate->is_active]);

        $message = $rate->is_active ? 'Đã kích hoạt mức giá.' : 'Đã ngừng mức giá.';

        return redirect()
            ->route('admin.service-packages.history', $servicePackage)
            ->with('success', $message);
    }
}
