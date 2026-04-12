<?php

namespace App\Policies;

use App\Models\Cycle;
use App\Models\Tender;
use App\Models\User;

/**
 * Reserved for future Tender-model-scoped checks.
 * Cycle-scoped tender actions live in CyclePolicy (placeBid, viewBids).
 */
class TenderPolicy
{
    public function view(User $user, Tender $tender): bool
    {
        return (int) $tender->user_id === (int) $user->id
            || (int) $tender->wishi->created_by === (int) $user->id;
    }
}
