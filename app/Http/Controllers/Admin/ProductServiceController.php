<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductServiceRequest;
use App\Http\Requests\Admin\UpdateProductServiceRequest;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProductServiceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ProductService::class);

        $items = ProductService::with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductService $item): array => [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'category_name' => $item->category?->name,
                'code' => $item->code,
                'name' => $item->name,
                'type' => $item->type->value,
                'type_label' => $item->type->label(),
                'unit' => $item->unit,
                'price' => (float) $item->price,
                'free_quantity_default' => $item->free_quantity_default,
                'use_in_checkout_inspection' => $item->use_in_checkout_inspection,
                'can_add_to_booking' => $item->can_add_to_booking,
                'is_active' => $item->is_active,
                'sort_order' => $item->sort_order,
                'description' => $item->description,
            ]);

        return Inertia::render('Admin/ProductServices/Index', [
            'items' => $items,
            'categories' => ProductServiceCategory::active()->orderBy('sort_order')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreProductServiceRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductService::class);

        ProductService::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('product-services.index')
            ->with('success', 'Đã thêm sản phẩm/dịch vụ.');
    }

    public function update(UpdateProductServiceRequest $request, ProductService $productService): RedirectResponse
    {
        $this->authorize('update', $productService);

        $productService->update([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('product-services.index')
            ->with('success', 'Đã cập nhật sản phẩm/dịch vụ.');
    }

    public function toggleActive(ProductService $productService): RedirectResponse
    {
        $this->authorize('toggleActive', $productService);

        $productService->update([
            'is_active' => ! $productService->is_active,
            'updated_by' => Auth::id(),
        ]);

        $message = $productService->is_active ? 'Đã kích hoạt.' : 'Đã ngừng sử dụng.';

        return redirect()
            ->route('product-services.index')
            ->with('success', $message);
    }
}
