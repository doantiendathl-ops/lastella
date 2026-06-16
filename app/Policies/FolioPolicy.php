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
        return $user->hasRole('ADMIN') && $folio->status === FolioStatus::Closed;
    }
}
