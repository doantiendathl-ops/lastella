<?php

namespace App\Repositories\Eloquent;

use App\Models\Setting;

class SettingRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Setting::class;
    }

    protected function searchable(): array
    {
        return ['key', 'group'];
    }

    protected function sortable(): array
    {
        return ['key', 'group', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'key';
    }

    protected function filterable(): array
    {
        return ['group'];
    }
}
