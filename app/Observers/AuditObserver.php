<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditObserver
{
    private const HIDDEN_KEYS = [
        'password',
        'remember_token',
    ];

    public function created(Model $model): void
    {
        $this->write($model, AuditAction::Created, null, $this->sanitize($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = Arr::except($model->getChanges(), ['updated_at']);

        if ($changed === []) {
            return;
        }

        $keys = array_keys($changed);

        $this->write(
            $model,
            AuditAction::Updated,
            $this->sanitize(Arr::only($model->getOriginal(), $keys)),
            $this->sanitize(Arr::only($model->getAttributes(), $keys)),
        );
    }

    public function deleted(Model $model): void
    {
        $this->write($model, AuditAction::Deleted, $this->sanitize($model->getOriginal()), null);
    }

    public function restored(Model $model): void
    {
        $this->write($model, AuditAction::Restored, null, $this->sanitize($model->getAttributes()));
    }

    private function write(Model $model, AuditAction $action, ?array $oldData, ?array $newData): void
    {
        if ($model instanceof AuditLog) {
            return;
        }

        $request = request();

        AuditLog::create([
            'action' => $action,
            'user_id' => auth()->id(),
            'entity_type' => $model::class,
            'entity_id' => $model->getKey(),
            'old_data' => $oldData,
            'new_data' => $newData,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function sanitize(array $attributes): array
    {
        return Arr::except($attributes, self::HIDDEN_KEYS);
    }
}
