<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $items = $this->auditLogs->paginate($request->validated())->through(fn (AuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action?->value,
            'user' => $log->user?->email ?? 'System',
            'entity_type' => class_basename($log->entity_type),
            'entity_id' => $log->entity_id,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Audit Logs',
            'baseUrl' => '/audit-logs',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'created_at', 'label' => 'Timestamp', 'sortable' => true],
                ['key' => 'action', 'label' => 'Action', 'sortable' => true],
                ['key' => 'user', 'label' => 'User'],
                ['key' => 'entity_type', 'label' => 'Entity', 'sortable' => true],
                ['key' => 'entity_id', 'label' => 'Entity ID'],
                ['key' => 'ip_address', 'label' => 'IP Address'],
            ],
            'filterFields' => [
                ['name' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => [
                    ['value' => 'created', 'label' => 'Created'],
                    ['value' => 'updated', 'label' => 'Updated'],
                    ['value' => 'deleted', 'label' => 'Deleted'],
                    ['value' => 'restored', 'label' => 'Restored'],
                ]],
                ['name' => 'entity_type', 'label' => 'Entity Type', 'type' => 'text'],
            ],
            'canCreate' => false,
            'readOnly' => true,
        ]);
    }
}
