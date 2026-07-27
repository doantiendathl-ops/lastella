<?php

namespace App\Repositories\Eloquent;

use App\Models\ProductServiceCategory;

class ProductServiceCategoryRepository extends EloquentRepository
{
    protected function model(): string
    {
        return ProductServiceCategory::class;
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
