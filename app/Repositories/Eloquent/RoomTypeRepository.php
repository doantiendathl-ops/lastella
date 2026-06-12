<?php

namespace App\Repositories\Eloquent;

use App\Models\RoomType;

class RoomTypeRepository extends EloquentRepository
{
    protected function model(): string
    {
        return RoomType::class;
    }

    protected function searchable(): array
    {
        return ['code', 'name', 'description'];
    }

    protected function sortable(): array
    {
        return ['code', 'name', 'standard_adults', 'max_adults', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'code';
    }
}
