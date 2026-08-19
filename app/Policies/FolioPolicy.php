<?php

namespace App\Policies;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\User;

class FolioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('folio.view');
    }

    public function view(User $user, Folio $folio): bool
    {
        return $this->viewAny($user);
    }

    public function close(User $user, Folio $folio): bool
    {
        return $user->can('folio.close') && $folio->status === FolioStatus::Open;
    }

    public function reopen(User $user, Folio $folio): bool
    {
        // User request (2026-08-19 chat) — was hasRole('ADMIN').
        return $user->can('folio.reopen') && $folio->status === FolioStatus::Closed;
    }
}
