<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wishi;

class AuditLogPolicy
{
    public function viewAny(User $user, Wishi $wishi): bool
    {
        return (int) $wishi->created_by === (int) $user->id;
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $log->wishi && (int) $log->wishi->created_by === (int) $user->id;
    }
}
