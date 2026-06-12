<?php

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;

class AuditLogRepository extends EloquentRepository
{
    protected function model(): string
    {
        return AuditLog::class;
    }

    protected function with(): array
    {
        return ['user'];
    }

    protected function searchable(): array
    {
        return ['action', 'entity_type', 'user.email'];
    }

    protected function sortable(): array
    {
        return ['action', 'entity_type', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function filterable(): array
    {
        return ['action', 'entity_type'];
    }
}
