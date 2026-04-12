<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WishiMember;

class WishiMemberPolicy
{
    public function approve(User $user, WishiMember $member): bool
    {
        return (int) $member->wishi->created_by === (int) $user->id
            && $member->status === 'pending';
    }

    public function reject(User $user, WishiMember $member): bool
    {
        return (int) $member->wishi->created_by === (int) $user->id
            && $member->status === 'pending';
    }

    public function remove(User $user, WishiMember $member): bool
    {
        if ((int) $member->wishi->created_by !== (int) $user->id) {
            return false;
        }
        if ((int) $member->user_id === (int) $member->wishi->created_by) {
            return false; // can't remove the creator
        }
        return true;
    }
}
