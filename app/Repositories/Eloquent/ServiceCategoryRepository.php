<?php

namespace App\Repositories\Eloquent;

use App\Models\ServiceCategory;

class ServiceCategoryRepository extends EloquentRepository
{
    protected function model(): string
    {
        return ServiceCategory::class;
    }

    protected function searchable(): array
    {
        return ['code', 'name'];
    }

    protected function sortable(): array
    {
        return ['code', 'name', 'sort_order', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }
}
