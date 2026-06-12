<?php

namespace App\Repositories\Eloquent;

use App\Models\Room;

class RoomRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Room::class;
    }

    protected function with(): array
    {
        return ['floor', 'roomType', 'resource'];
    }

    protected function searchable(): array
    {
        return ['room_number', 'resource.name', 'resource.code'];
    }

    protected function sortable(): array
    {
        return ['room_number', 'status', 'floor_id', 'room_type_id', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'room_number';
    }

    protected function filterable(): array
    {
        return ['floor_id', 'room_type_id', 'status'];
    }
}
