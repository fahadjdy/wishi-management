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
        // Once the WISHI has started (or beyond), membership is locked —
        // removing a member after activation would invalidate cycle contribs
        // and token assignments. Admin can only cancel members while the
        // WISHI is still in draft/planned state.
        if (! in_array($member->wishi->status, ['draft', 'planned'], true)) {
            return false;
        }
        return true;
    }
}
