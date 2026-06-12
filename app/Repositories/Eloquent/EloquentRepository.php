<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class EloquentRepository implements RepositoryInterface
{
    abstract protected function model(): string;

    public function query(): Builder
    {
        $model = $this->model();

        return $model::query();
    }

    public function paginate(array $params = [], int $defaultPerPage = 15): LengthAwarePaginator
    {
        $query = $this->query();

        if ($with = $this->with()) {
            $query->with($with);
        }

        $this->applySearch($query, trim((string) ($params['search'] ?? '')));
        $this->applyFilters($query, $params);
        $this->applySorting($query, $params);

        $perPage = min(max((int) ($params['per_page'] ?? $defaultPerPage), 5), 100);

        return $query->paginate($perPage)->withQueryString();
    }

    public function create(array $data): Model
    {
        return $this->query()->create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->fill($data)->save();

        return $model->refresh();
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    protected function with(): array
    {
        return [];
    }

    protected function searchable(): array
    {
        return [];
    }

    protected function sortable(): array
    {
        return ['created_at'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function filterable(): array
    {
        return [];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '' || $this->searchable() === []) {
            return;
        }

        $query->where(function (Builder $query) use ($search): void {
            foreach ($this->searchable() as $column) {
                $query->orWhere(function (Builder $query) use ($column, $search): void {
                    $this->applyLike($query, $column, $search);
                });
            }
        });
    }

    protected function applyFilters(Builder $query, array $params): void
    {
        foreach ($this->filterable() as $param => $column) {
            if (is_int($param)) {
                $param = $column;
            }

            $value = $params[$param] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $this->applyExact($query, $column, $value);
        }
    }

    protected function applySorting(Builder $query, array $params): void
    {
        $sort = (string) ($params['sort'] ?? $this->defaultSort());
        $direction = strtolower((string) ($params['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if (! in_array($sort, $this->sortable(), true)) {
            $sort = $this->defaultSort();
        }

        $query->orderBy($sort, $direction);
    }

    private function applyLike(Builder $query, string $column, string $search): void
    {
        if (str_contains($column, '.')) {
            [$relation, $relationColumn] = explode('.', $column, 2);

            $query->whereHas($relation, fn (Builder $query): Builder => $query->where($relationColumn, 'like', "%{$search}%"));

            return;
        }

        $query->where($column, 'like', "%{$search}%");
    }

    private function applyExact(Builder $query, string $column, mixed $value): void
    {
        if (str_contains($column, '.')) {
            [$relation, $relationColumn] = explode('.', $column, 2);

            $query->whereHas($relation, fn (Builder $query): Builder => $query->where($relationColumn, $value));

            return;
        }

        $query->where($column, $value);
    }
}
