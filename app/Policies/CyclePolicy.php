<?php

namespace App\Policies;

use App\Models\Cycle;
use App\Models\User;

class CyclePolicy
{
    public function view(User $user, Cycle $cycle): bool
    {
        $wishi = $cycle->wishi;
        if ((int) $wishi->created_by === (int) $user->id) {
            return true;
        }
        return $wishi->members()
            ->where('user_id', $user->id)
            ->whereIn('status', ['approved', 'active'])
            ->exists();
    }

    public function advance(User $user, Cycle $cycle): bool
    {
        return (int) $cycle->wishi->created_by === (int) $user->id;
    }

    public function selectWinner(User $user, Cycle $cycle): \Illuminate\Auth\Access\Response
    {
        if ((int) $cycle->wishi->created_by !== (int) $user->id) {
            return \Illuminate\Auth\Access\Response::deny('Only the WISHI admin can select a winner.');
        }
        if ($cycle->cycle_number === 1) {
            return \Illuminate\Auth\Access\Response::deny('Cycle #1 is the organizer payout — winner is pre-assigned to admin and cannot be changed.');
        }
        if (! in_array($cycle->status, ['selection_pending', 'bidding_open', 'contribution_open'], true)) {
            return \Illuminate\Auth\Access\Response::deny('Cycle is not in a selectable state.');
        }
        return \Illuminate\Auth\Access\Response::allow();
    }

    public function handleSurplus(User $user, Cycle $cycle): bool
    {
        return (int) $cycle->wishi->created_by === (int) $user->id
            && (float) $cycle->surplus > 0;
    }

    public function recordPayout(User $user, Cycle $cycle): bool
    {
        return (int) $cycle->wishi->created_by === (int) $user->id
            && $cycle->winner_id !== null;
    }

    public function viewBids(User $user, Cycle $cycle): bool
    {
        if ((int) $cycle->wishi->created_by === (int) $user->id) {
            return true;
        }
        if ($cycle->tender_closes_at && $cycle->tender_closes_at->isPast()) {
            return $cycle->wishi->members()
                ->where('user_id', $user->id)
                ->whereIn('status', ['approved', 'active'])
                ->exists();
        }
        return false;
    }

    public function placeBid(User $user, Cycle $cycle): \Illuminate\Auth\Access\Response
    {
        if ($cycle->cycle_number === 1) {
            return \Illuminate\Auth\Access\Response::deny('Cycle #1 is the organizer payout — no bidding.');
        }
        if ($cycle->mode !== 'tender') {
            return \Illuminate\Auth\Access\Response::deny('This cycle is not a tender cycle.');
        }
        if (! $cycle->isBiddingOpen()) {
            return \Illuminate\Auth\Access\Response::deny('Bidding window is not open.');
        }
        $member = $cycle->wishi->members()
            ->where('user_id', $user->id)
            ->whereIn('status', ['approved', 'active'])
            ->first();
        if (! $member) {
            return \Illuminate\Auth\Access\Response::deny('You are not an active member of this WISHI.');
        }
        if ($member->has_won) {
            return \Illuminate\Auth\Access\Response::deny('You have already won a cycle in this WISHI.');
        }
        return \Illuminate\Auth\Access\Response::allow();
    }
}
