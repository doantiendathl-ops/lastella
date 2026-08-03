<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChargeType;
use App\Enums\PackageCalculationStrategy;
use App\Enums\PackagePostingFrequency;
use App\Enums\PackageQuantityMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServicePackageRequest;
use App\Http\Requests\Admin\UpdateServicePackageRequest;
use App\Models\ServicePackage;
use App\Services\BusinessDateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServicePackageController extends Controller
{
    public function index(BusinessDateService $businessDateService): Response
    {
        $this->authorize('viewAny', ServicePackage::class);

        $businessDate = $businessDateService->currentBusinessDate()->toDateString();

        $packages = ServicePackage::orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function (ServicePackage $package) use ($businessDate): array {
                $currentRate = $package->currentRate($businessDate);

                return [
                    'id'                          => $package->id,
                    'code'                        => $package->code,
                    'name'                        => $package->name,
                    'description'                 => $package->description,
                    'charge_type'                 => $package->charge_type,
                    'charge_type_label'           => ChargeType::tryFrom($package->charge_type)?->label() ?? $package->charge_type,
                    'calculation_strategy'        => $package->calculation_strategy,
                    'calculation_strategy_label'  => PackageCalculationStrategy::tryFrom($package->calculation_strategy)?->label() ?? $package->calculation_strategy,
                    'quantity_mode'               => $package->quantity_mode,
                    'quantity_mode_label'         => PackageQuantityMode::tryFrom($package->quantity_mode)?->label() ?? $package->quantity_mode,
                    'default_quantity'            => $package->default_quantity,
                    'unit_label'                  => $package->unit_label,
                    'posting_frequency'           => $package->posting_frequency,
                    'posting_frequency_label'     => PackagePostingFrequency::tryFrom($package->posting_frequency)?->label() ?? $package->posting_frequency,
                    'is_active'                   => $package->is_active,
                    'is_bookable'                 => $package->is_bookable,
                    'display_order'               => $package->display_order,
                    'current_rate'                => $currentRate !== null ? (float) $currentRate->unit_price : null,
                    'current_rate_effective_from' => $currentRate?->effective_from?->toDateString(),
                    'rate_count'                  => $package->rates()->count(),
                    'has_been_used'               => $package->hasBeenUsed(),
                    'can_change_code'             => ! $package->hasBeenUsed(),
                    'can_delete'                  => ! $package->hasBeenUsed(),
                ];
            });

        return Inertia::render('Admin/ServicePackages/Index', [
            'packages'             => $packages,
            'chargeTypes'         => array_values(array_filter(
                ChargeType::options(),
                fn (array $opt): bool => $opt['value'] !== ChargeType::Room->value,
            )),
            'calculationStrategies' => array_map(
                fn (PackageCalculationStrategy $s): array => ['value' => $s->value, 'label' => $s->label()],
                PackageCalculationStrategy::implemented(),
            ),
            'quantityModes'        => array_map(
                fn (PackageQuantityMode $m): array => ['value' => $m->value, 'label' => $m->label()],
                PackageQuantityMode::implemented(),
            ),
            'postingFrequencies'   => array_map(
                fn (PackagePostingFrequency $f): array => ['value' => $f->value, 'label' => $f->label()],
                PackagePostingFrequency::implemented(),
            ),
            'businessDate'         => $businessDate,
        ]);
    }

    public function store(StoreServicePackageRequest $request): RedirectResponse
    {
        $this->authorize('create', ServicePackage::class);

        ServicePackage::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.service-packages.index')
            ->with('success', 'Đã tạo gói dịch vụ.');
    }

    public function update(UpdateServicePackageRequest $request, ServicePackage $servicePackage): RedirectResponse
    {
        $this->authorize('update', $servicePackage);

        $servicePackage->update([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.service-packages.index')
            ->with('success', 'Đã cập nhật gói dịch vụ.');
    }

    public function toggle(Request $request, ServicePackage $servicePackage): RedirectResponse
    {
        $this->authorize('toggle', $servicePackage);

        $data = $request->validate([
            'field' => ['required', Rule::in(['is_active', 'is_bookable'])],
        ]);

        $field = $data['field'];

        $servicePackage->update([
            $field       => ! $servicePackage->{$field},
            'updated_by' => Auth::id(),
        ]);

        $message = $field === 'is_active'
            ? ($servicePackage->is_active ? 'Đã kích hoạt gói dịch vụ.' : 'Đã ngừng hoạt động gói dịch vụ.')
            : ($servicePackage->is_bookable ? 'Đã cho phép đăng ký mới.' : 'Đã ngừng cho phép đăng ký mới.');

        return redirect()
            ->route('admin.service-packages.index')
            ->with('success', $message);
    }

    public function history(ServicePackage $servicePackage): Response
    {
        $this->authorize('view', $servicePackage);

        $rates = $servicePackage->rates()
            ->with('createdBy')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn ($rate): array => [
                'id'              => $rate->id,
                'unit_price'      => (float) $rate->unit_price,
                'effective_from'  => $rate->effective_from->toDateString(),
                'is_active'       => $rate->is_active,
                'tax_rate'        => (float) $rate->tax_rate,
                'gl_account_code' => $rate->gl_account_code,
                'created_by'      => $rate->createdBy?->name ?? '—',
                'created_at'      => $rate->created_at->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Admin/ServicePackages/History', [
            'package' => [
                'id'         => $servicePackage->id,
                'code'       => $servicePackage->code,
                'name'       => $servicePackage->name,
                'unit_label' => $servicePackage->unit_label,
            ],
            'rates'   => $rates,
        ]);
    }
}
