<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreProductServiceCategoryRequest;
use App\Http\Requests\Admin\UpdateProductServiceCategoryRequest;
use App\Models\ProductServiceCategory;
use App\Services\ProductServiceCategoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProductServiceCategoryController extends Controller
{
    public function __construct(private readonly ProductServiceCategoryService $categories)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', ProductServiceCategory::class);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Nhóm sản phẩm/dịch vụ',
            'baseUrl' => '/product-service-categories',
            'items' => $this->categories->paginate($request->validated())->through(fn (ProductServiceCategory $category): array => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active ? 'Hoạt động' : 'Ngừng',
            ]),
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'code', 'label' => 'Mã', 'sortable' => true],
                ['key' => 'name', 'label' => 'Tên', 'sortable' => true],
                ['key' => 'sort_order', 'label' => 'Thứ tự', 'sortable' => true],
                ['key' => 'is_active', 'label' => 'Trạng thái'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ProductServiceCategory::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo nhóm sản phẩm/dịch vụ', '/product-service-categories', 'post', ['is_active' => true]));
    }

    public function store(StoreProductServiceCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductServiceCategory::class);
        $this->categories->create($request->validated());

        return redirect('/product-service-categories')->with('success', 'Đã tạo nhóm sản phẩm/dịch vụ.');
    }

    public function edit(ProductServiceCategory $productServiceCategory): Response
    {
        $this->authorize('update', $productServiceCategory);

        return Inertia::render('Admin/CrudForm', $this->formProps(
            'Sửa nhóm sản phẩm/dịch vụ',
            "/product-service-categories/{$productServiceCategory->id}",
            'put',
            $productServiceCategory->only(['code', 'name', 'sort_order', 'is_active']),
        ));
    }

    public function update(UpdateProductServiceCategoryRequest $request, ProductServiceCategory $productServiceCategory): RedirectResponse
    {
        $this->authorize('update', $productServiceCategory);
        $this->categories->update($productServiceCategory, $request->validated());

        return redirect('/product-service-categories')->with('success', 'Đã cập nhật nhóm sản phẩm/dịch vụ.');
    }

    public function destroy(ProductServiceCategory $productServiceCategory): RedirectResponse
    {
        $this->authorize('delete', $productServiceCategory);
        $this->categories->delete($productServiceCategory);

        return redirect('/product-service-categories')->with('success', 'Đã xóa nhóm sản phẩm/dịch vụ.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/product-service-categories',
            'values' => $values,
            'fields' => [
                ['name' => 'code', 'label' => 'Mã', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Tên', 'type' => 'text', 'required' => true],
                ['name' => 'sort_order', 'label' => 'Thứ tự', 'type' => 'number', 'required' => true],
                ['name' => 'is_active', 'label' => 'Đang hoạt động', 'type' => 'checkbox'],
            ],
        ];
    }
}
