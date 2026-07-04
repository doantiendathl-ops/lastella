<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChargeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRateRequest;
use App\Http\Requests\Admin\UpdateServiceRateRequest;
use App\Models\ServiceRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ServiceRateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ServiceRate::class);

        $rates = ServiceRate::orderBy('display_order')
            ->orderBy('charge_type')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (ServiceRate $rate): array => [
                'id'              => $rate->id,
                'name'            => $rate->name,
                'charge_type'     => $rate->charge_type,
                'charge_label'    => ChargeType::from($rate->charge_type)->label(),
                'unit_price'      => (float) $rate->unit_price,
                'effective_from'  => $rate->effective_from->toDateString(),
                'unit_label'      => $rate->unit_label,
                'gl_account_code' => $rate->gl_account_code,
                'is_active'       => $rate->is_active,
                'display_order'   => $rate->display_order,
            ]);

        $chargeTypes = array_values(array_filter(
            ChargeType::options(),
            fn (array $opt): bool => $opt['value'] !== ChargeType::Room->value,
        ));

        return Inertia::render('Admin/ServiceRates/Index', [
            'rates'       => $rates,
            'chargeTypes' => $chargeTypes,
        ]);
    }

    public function store(StoreServiceRateRequest $request): RedirectResponse
    {
        $this->authorize('create', ServiceRate::class);

        ServiceRate::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.service-rates.index')
            ->with('success', 'Đã thêm dịch vụ.');
    }

    public function update(UpdateServiceRateRequest $request, ServiceRate $serviceRate): RedirectResponse
    {
        $this->authorize('update', $serviceRate);

        // ADR-66: price change inserts a new row; non-price field edits update in place
        $data     = $request->validated();
        $newPrice = (float) $data['unit_price'];
        $newDate  = $data['effective_from'];

        $priceChanged = (float) $serviceRate->unit_price !== $newPrice
            || $serviceRate->effective_from->toDateString() !== $newDate;

        if ($priceChanged) {
            ServiceRate::create([
                ...$data,
                'created_by' => Auth::id(),
            ]);
        } else {
            $serviceRate->update($data);
        }

        return redirect()
            ->route('admin.service-rates.index')
            ->with('success', 'Đã cập nhật dịch vụ.');
    }

    public function toggleActive(ServiceRate $serviceRate): RedirectResponse
    {
        $this->authorize('toggleActive', $serviceRate);

        $serviceRate->update(['is_active' => ! $serviceRate->is_active]);

        $message = $serviceRate->is_active ? 'Đã kích hoạt dịch vụ.' : 'Đã tắt dịch vụ.';

        return redirect()
            ->route('admin.service-rates.index')
            ->with('success', $message);
    }

    public function history(string $chargeType): Response
    {
        $this->authorize('viewAny', ServiceRate::class);

        $type = ChargeType::tryFrom(strtoupper($chargeType));
        abort_if($type === null, 422, 'Invalid charge type.');

        $rates = ServiceRate::where('charge_type', $type->value)
            ->with('createdBy')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (ServiceRate $rate): array => [
                'id'              => $rate->id,
                'charge_type'     => $rate->charge_type,
                'charge_label'    => $type->label(),
                'name'            => $rate->name,
                'unit_price'      => (float) $rate->unit_price,
                'effective_from'  => $rate->effective_from->toDateString(),
                'is_active'       => $rate->is_active,
                'tax_rate'        => (float) $rate->tax_rate,
                'gl_account_code' => $rate->gl_account_code,
                'created_by'      => $rate->createdBy?->name ?? '—',
                'created_at'      => $rate->created_at->format('Y-m-d H:i'),
            ])
            ->all();

        return Inertia::render('Admin/ServiceRates/History', [
            'chargeType'  => $type->value,
            'chargeLabel' => $type->label(),
            'rates'       => $rates,
        ]);
    }
}
