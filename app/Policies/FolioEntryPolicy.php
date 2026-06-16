<?php

namespace App\Policies;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\User;

class FolioEntryPolicy
{
    public function create(User $user, Folio $folio): bool
    {
        return $user->can('charge.create') && $folio->status === FolioStatus::Open;
    }

    public function void(User $user, FolioEntry $entry): bool
    {
        if ($user->hasRole('ADMIN')) {
            return true;
        }

        if ($user->can('charge.void')) {
            return $entry->created_at->isToday();
        }

        return false;
    }
}
