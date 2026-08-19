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
        // User request (2026-08-19 chat) — was hasRole('ADMIN'); the "any
        // date" bypass (charge.void alone only allows voiding TODAY's
        // entry, see below) is now its own grantable permission.
        if ($user->can('charge.void_any_date')) {
            return true;
        }

        if ($user->can('charge.void')) {
            return $entry->created_at->isToday();
        }

        return false;
    }
}
