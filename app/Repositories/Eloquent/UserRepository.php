<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserRepository extends EloquentRepository
{
    protected function model(): string
    {
        return User::class;
    }

    protected function with(): array
    {
        return ['roles'];
    }

    protected function searchable(): array
    {
        return ['name', 'email'];
    }

    protected function sortable(): array
    {
        return ['name', 'email', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function applyFilters(Builder $query, array $params): void
    {
        parent::applyFilters($query, $params);

        if (! empty($params['role'])) {
            $query->whereHas('roles', fn (Builder $query): Builder => $query->where('name', $params['role']));
        }
    }
}
