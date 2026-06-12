<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreFloorRequest;
use App\Http\Requests\Admin\UpdateFloorRequest;
use App\Models\Floor;
use App\Services\FloorService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FloorController extends Controller
{
    public function __construct(private readonly FloorService $floors)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', Floor::class);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Floors',
            'baseUrl' => '/floors',
            'items' => $this->floors->paginate($request->validated()),
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'code', 'label' => 'Code', 'sortable' => true],
                ['key' => 'name', 'label' => 'Name', 'sortable' => true],
                ['key' => 'sort_order', 'label' => 'Sort', 'sortable' => true],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Floor::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create Floor', '/floors', 'post'));
    }

    public function store(StoreFloorRequest $request): RedirectResponse
    {
        $this->authorize('create', Floor::class);
        $this->floors->create($request->validated());

        return redirect('/floors')->with('success', 'Floor created.');
    }

    public function edit(Floor $floor): Response
    {
        $this->authorize('update', $floor);

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit Floor', "/floors/{$floor->id}", 'put', $floor->only(['code', 'name', 'sort_order'])));
    }

    public function update(UpdateFloorRequest $request, Floor $floor): RedirectResponse
    {
        $this->authorize('update', $floor);
        $this->floors->update($floor, $request->validated());

        return redirect('/floors')->with('success', 'Floor updated.');
    }

    public function destroy(Floor $floor): RedirectResponse
    {
        $this->authorize('delete', $floor);
        $this->floors->delete($floor);

        return redirect('/floors')->with('success', 'Floor deleted.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/floors',
            'values' => $values,
            'fields' => [
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'sort_order', 'label' => 'Sort Order', 'type' => 'number', 'required' => true],
            ],
        ];
    }
}
