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
        if ($this->isAdmin($user, $wishi) || $this->isMember($user, $wishi)) {
            return true;
        }
        // Discovery: non-members may only see WISHIs in the `planned` state.
        // `draft` stays admin-only, and `active`/`completed`/`cancelled` are
        // visible only to the admin or members who actually joined.
        return $wishi->status === 'planned';
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

    public function join(User $user, Wishi $wishi): \Illuminate\Auth\Access\Response
    {
        if ($this->isAdmin($user, $wishi)) {
            return \Illuminate\Auth\Access\Response::deny('Admins cannot join their own WISHI as a member.');
        }
        if (! in_array($wishi->status, ['planned', 'active'], true)) {
            return \Illuminate\Auth\Access\Response::deny('This WISHI is not accepting members.');
        }
        if ($this->isMember($user, $wishi)) {
            return \Illuminate\Auth\Access\Response::deny('You already have a membership or pending request.');
        }
        return \Illuminate\Auth\Access\Response::allow();
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
