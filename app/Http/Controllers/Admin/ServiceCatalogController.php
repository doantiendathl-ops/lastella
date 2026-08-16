<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\BusinessDateService;
use App\Services\ServicePricingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 2/3) — the
 * unified Service Catalog admin screen. Deliberately its own new route
 * (/admin/services) rather than replacing /admin/service-packages or
 * /admin/service-rates in this slice — both legacy routes stay reachable
 * (Section 29 allows deferring the redirect/deprecation step).
 */
class ServiceCatalogController extends Controller
{
    public function index(BusinessDateService $businessDateService, ServicePricingResolver $pricing): Response
    {
        $this->authorize('viewAny', Service::class);

        $businessDate = $businessDateService->currentBusinessDate()->toDateString();

        $services = Service::with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function (Service $service) use ($businessDate, $pricing): array {
                $currentPrice = $pricing->resolve($service, $businessDate);

                return [
                    'id' => $service->id,
                    'category_id' => $service->category_id,
                    'category_name' => $service->category?->name,
                    'code' => $service->code,
                    'name' => $service->name,
                    'description' => $service->description,
                    'is_chargeable' => $service->is_chargeable,
                    'scope' => $service->scope->value,
                    'scope_label' => $service->scope->label(),
                    'billing_mode' => $service->billing_mode->value,
                    'billing_mode_label' => $service->billing_mode->label(),
                    'quantity_enabled' => $service->quantity_enabled,
                    'default_quantity' => $service->default_quantity,
                    'unit_label' => $service->unit_label,
                    'fulfillment_required' => $service->fulfillment_required,
                    'is_active' => $service->is_active,
                    'is_bookable' => $service->is_bookable,
                    'sort_order' => $service->sort_order,
                    'current_price' => $currentPrice !== null ? (float) $currentPrice->unit_price : null,
                    'current_price_effective_from' => $currentPrice?->effective_from?->toDateString(),
                    'price_count' => $service->prices()->count(),
                    'has_been_used' => $service->hasBeenUsed(),
                    'can_change_identity' => ! $service->hasBeenUsed(),
                ];
            });

        return Inertia::render('Admin/Services/Index', [
            'services' => $services,
            'categories' => ServiceCategory::active()->orderBy('sort_order')->get(['id', 'name']),
            'scopes' => ServiceScope::options(),
            'billingModes' => ServiceBillingMode::options(),
            'businessDate' => $businessDate,
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $this->authorize('create', Service::class);

        Service::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.services.index')
            ->with('success', 'Đã tạo dịch vụ.');
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        $service->update([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.services.index')
            ->with('success', 'Đã cập nhật dịch vụ.');
    }

    public function toggle(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('toggle', $service);

        $data = $request->validate([
            'field' => ['required', Rule::in(['is_active', 'is_bookable'])],
        ]);

        $field = $data['field'];

        $service->update([
            $field => ! $service->{$field},
            'updated_by' => Auth::id(),
        ]);

        $message = $field === 'is_active'
            ? ($service->is_active ? 'Đã kích hoạt dịch vụ.' : 'Đã ngừng hoạt động dịch vụ.')
            : ($service->is_bookable ? 'Đã cho phép đăng ký mới.' : 'Đã ngừng cho phép đăng ký mới.');

        return redirect()
            ->route('admin.services.index')
            ->with('success', $message);
    }
}
