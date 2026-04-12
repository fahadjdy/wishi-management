<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wishi;

class WishiPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Wishi $wishi): bool
    {
        return $this->isAdmin($user, $wishi) || $this->isMember($user, $wishi);
    }

    public function create(User $user): \Illuminate\Auth\Access\Response
    {
        return $user->isPlatformAdmin()
            ? \Illuminate\Auth\Access\Response::allow()
            : \Illuminate\Auth\Access\Response::deny('Only platform admins can create new WISHIs.');
    }

    public function update(User $user, Wishi $wishi): bool
    {
        return $this->isAdmin($user, $wishi) && $wishi->status !== 'completed';
    }

    public function delete(User $user, Wishi $wishi): bool
    {
        return $this->isAdmin($user, $wishi) && in_array($wishi->status, ['draft', 'cancelled']);
    }

    public function manageMembers(User $user, Wishi $wishi): bool
    {
        return $this->isAdmin($user, $wishi);
    }

    public function viewAuditLog(User $user, Wishi $wishi): bool
    {
        return $this->isAdmin($user, $wishi);
    }

    public function join(User $user, Wishi $wishi): bool
    {
        if ($wishi->status !== 'active' && $wishi->status !== 'draft') {
            return false;
        }
        if ($this->isMember($user, $wishi)) {
            return false;
        }
        return true;
    }

    protected function isAdmin(User $user, Wishi $wishi): bool
    {
        return (int) $wishi->created_by === (int) $user->id;
    }

    protected function isMember(User $user, Wishi $wishi): bool
    {
        return $wishi->members()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->exists();
    }
}
