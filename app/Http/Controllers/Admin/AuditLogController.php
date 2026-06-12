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
            'action' => $log->action?->label(),
            'user' => $log->user?->email ?? 'Hệ thống',
            'entity_type' => class_basename($log->entity_type),
            'entity_id' => $log->entity_id,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Nhật ký',
            'baseUrl' => '/audit-logs',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'created_at', 'label' => 'Thời điểm', 'sortable' => true],
                ['key' => 'action', 'label' => 'Hành động', 'sortable' => true],
                ['key' => 'user', 'label' => 'Người dùng'],
                ['key' => 'entity_type', 'label' => 'Đối tượng', 'sortable' => true],
                ['key' => 'entity_id', 'label' => 'ID đối tượng'],
                ['key' => 'ip_address', 'label' => 'Địa chỉ IP'],
            ],
            'filterFields' => [
                ['name' => 'action', 'label' => 'Hành động', 'type' => 'select', 'options' => [
                    ['value' => 'created', 'label' => 'Đã tạo'],
                    ['value' => 'updated', 'label' => 'Đã cập nhật'],
                    ['value' => 'deleted', 'label' => 'Đã xóa'],
                    ['value' => 'restored', 'label' => 'Đã khôi phục'],
                ]],
                ['name' => 'entity_type', 'label' => 'Loại đối tượng', 'type' => 'text'],
            ],
            'canCreate' => false,
            'readOnly' => true,
        ]);
    }
}
