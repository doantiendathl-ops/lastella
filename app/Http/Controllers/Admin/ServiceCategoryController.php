<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreServiceCategoryRequest;
use App\Http\Requests\Admin\UpdateServiceCategoryRequest;
use App\Models\ServiceCategory;
use App\Services\ServiceCategoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 3) — category
 * catalog CRUD, database/UI-managed (never hard-coded), reusing the exact
 * generic CrudIndex/CrudForm pattern ProductServiceCategoryController
 * already uses.
 */
class ServiceCategoryController extends Controller
{
    public function __construct(private readonly ServiceCategoryService $categories)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', ServiceCategory::class);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Danh mục Dịch vụ & Yêu cầu',
            'baseUrl' => '/admin/service-categories',
            'items' => $this->categories->paginate($request->validated())->through(fn (ServiceCategory $category): array => [
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
        $this->authorize('create', ServiceCategory::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo danh mục', '/admin/service-categories', 'post', ['is_active' => true]));
    }

    public function store(StoreServiceCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', ServiceCategory::class);
        $this->categories->create($request->validated());

        return redirect('/admin/service-categories')->with('success', 'Đã tạo danh mục.');
    }

    public function edit(ServiceCategory $serviceCategory): Response
    {
        $this->authorize('update', $serviceCategory);

        return Inertia::render('Admin/CrudForm', $this->formProps(
            'Sửa danh mục',
            "/admin/service-categories/{$serviceCategory->id}",
            'put',
            $serviceCategory->only(['code', 'name', 'sort_order', 'is_active']),
        ));
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): RedirectResponse
    {
        $this->authorize('update', $serviceCategory);
        $this->categories->update($serviceCategory, $request->validated());

        return redirect('/admin/service-categories')->with('success', 'Đã cập nhật danh mục.');
    }

    public function destroy(ServiceCategory $serviceCategory): RedirectResponse
    {
        $this->authorize('delete', $serviceCategory);
        $this->categories->delete($serviceCategory);

        return redirect('/admin/service-categories')->with('success', 'Đã xóa danh mục.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/admin/service-categories',
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
