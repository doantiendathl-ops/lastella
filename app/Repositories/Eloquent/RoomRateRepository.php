<?php

namespace App\Repositories\Eloquent;

use App\Models\RoomRate;

class RoomRateRepository extends EloquentRepository
{
    protected function model(): string
    {
        return RoomRate::class;
    }

    protected function with(): array
    {
        return ['roomType'];
    }

    protected function searchable(): array
    {
        return ['roomType.code', 'roomType.name'];
    }

    protected function sortable(): array
    {
        return ['valid_from', 'valid_to', 'overnight_price', 'status', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'valid_from';
    }

    protected function filterable(): array
    {
        return ['room_type_id', 'status'];
    }
}
