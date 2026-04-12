<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use App\Notifications\MemberStatusNotification;
use App\Notifications\WishiFullNotification;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function __construct(protected AuditService $audit) {}

    public function requestJoin(Wishi $wishi, User $user): WishiMember
    {
        $this->guardEligibility($wishi, $user);

        return DB::transaction(function () use ($wishi, $user) {
            $existing = WishiMember::where('wishi_id', $wishi->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing && in_array($existing->status, ['pending', 'approved', 'active'])) {
                throw new \DomainException('You are already a member or have a pending request.');
            }

            $status = $wishi->require_approval ? 'pending' : 'approved';
            $member = $existing
                ? tap($existing)->update(['status' => $status, 'joined_at' => $status === 'approved' ? now() : null])
                : WishiMember::create([
                    'wishi_id' => $wishi->id,
                    'user_id' => $user->id,
                    'status' => $status,
                    'joined_at' => $status === 'approved' ? now() : null,
                ]);

            $this->audit->log($wishi, $user, 'member_join_requested', "User #{$user->id} requested to join", [
                'user_id' => $user->id,
                'auto_approved' => $status === 'approved',
            ]);

            // If auto-approved, also check if we just hit capacity
            if ($status === 'approved') {
                $this->notifyIfJustFilled($wishi);
            }

            return $member;
        });
    }

    /**
     * Admin adds a user to a WISHI. Creates an "invited" WishiMember row the user
     * must accept (or decline) from their dashboard before the seat is counted.
     */
    public function invite(Wishi $wishi, User $user, User $actor): WishiMember
    {
        if ((int) $wishi->created_by === (int) $user->id) {
            throw new \DomainException('Admins cannot be invited as members of their own WISHI.');
        }
        if (in_array($wishi->status, ['completed', 'cancelled'], true)) {
            throw new \DomainException('This WISHI is no longer accepting members.');
        }
        if ($wishi->activeMembers()->count() >= (int) $wishi->total_members) {
            throw new \DomainException('This WISHI is already at full capacity.');
        }

        return DB::transaction(function () use ($wishi, $user, $actor) {
            $existing = WishiMember::where('wishi_id', $wishi->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing && in_array($existing->status, ['pending', 'approved', 'active'], true)) {
                throw new \DomainException('User is already a member or has a pending request/invite.');
            }

            $row = $existing
                ? tap($existing)->update(['status' => 'pending', 'invited_by_admin' => true, 'joined_at' => null])
                : WishiMember::create([
                    'wishi_id' => $wishi->id,
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'invited_by_admin' => true,
                ]);

            $this->audit->log($wishi, $actor, 'member_invited', "Admin invited user #{$user->id}", [
                'user_id' => $user->id,
                'invited_by_admin' => true,
            ]);

            $user->notify(new MemberStatusNotification($wishi, 'invited',
                'The admin has invited you to join. Open your dashboard to accept or decline.'));

            return $row;
        });
    }

    public function acceptInvite(WishiMember $member, User $user): WishiMember
    {
        if ((int) $member->user_id !== (int) $user->id) {
            throw new \DomainException('You can only accept your own invitation.');
        }
        if (! $member->invited_by_admin || $member->status !== 'pending') {
            throw new \DomainException('No pending invitation to accept.');
        }
        if ($member->wishi->activeMembers()->count() >= (int) $member->wishi->total_members) {
            throw new \DomainException('WISHI is already full.');
        }
        return DB::transaction(function () use ($member, $user) {
            $member->update(['status' => 'approved', 'joined_at' => now()]);
            $this->audit->log($member->wishi, $user, 'invite_accepted', 'User accepted admin invitation',
                ['user_id' => $user->id]);
            $this->notifyIfJustFilled($member->wishi->fresh());
            return $member->fresh();
        });
    }

    public function declineInvite(WishiMember $member, User $user, ?string $reason = null): void
    {
        if ((int) $member->user_id !== (int) $user->id) {
            throw new \DomainException('You can only decline your own invitation.');
        }
        if (! $member->invited_by_admin || $member->status !== 'pending') {
            throw new \DomainException('No pending invitation to decline.');
        }
        DB::transaction(function () use ($member, $user, $reason) {
            $member->update(['status' => 'removed']);
            $member->delete();
            $this->audit->log($member->wishi, $user, 'invite_declined', 'User declined admin invitation', [
                'user_id' => $user->id,
                'reason' => $reason,
            ]);
        });
    }

    public function approve(WishiMember $member, User $actor): WishiMember
    {
        return DB::transaction(function () use ($member, $actor) {
            $current = $member->wishi->activeMembers()->count();
            if ($current >= $member->wishi->total_members) {
                throw new \DomainException('WISHI is already at full capacity.');
            }
            $member->update([
                'status' => 'approved',
                'joined_at' => $member->joined_at ?? now(),
            ]);
            $this->audit->log($member->wishi, $actor, 'member_approved', "Member #{$member->user_id} approved", [
                'member_user_id' => $member->user_id,
            ]);

            // Notify the member themselves
            $member->user?->notify(new MemberStatusNotification($member->wishi, 'approved'));

            // Notify the admin if this approval just filled the WISHI
            $this->notifyIfJustFilled($member->wishi->fresh());

            return $member->fresh();
        });
    }

    public function reject(WishiMember $member, User $actor, ?string $reason = null): WishiMember
    {
        $member->update(['status' => 'removed']);
        $this->audit->log($member->wishi, $actor, 'member_rejected', "Member #{$member->user_id} rejected", [
            'member_user_id' => $member->user_id,
            'reason' => $reason,
        ]);
        $member->user?->notify(new MemberStatusNotification($member->wishi, 'rejected', $reason));
        return $member;
    }

    public function remove(WishiMember $member, User $actor, ?string $reason = null): WishiMember
    {
        $wishi = $member->wishi;
        $user = $member->user;
        $member->update(['status' => 'removed']);
        $member->delete();
        $this->audit->log($wishi, $actor, 'member_removed', "Member #{$member->user_id} removed", [
            'member_user_id' => $member->user_id,
            'reason' => $reason,
        ]);
        $user?->notify(new MemberStatusNotification($wishi, 'removed', $reason));
        return $member;
    }

    protected function guardEligibility(Wishi $wishi, User $user): void
    {
        if ((int) $wishi->created_by === (int) $user->id) {
            throw new \DomainException('Admins cannot join their own WISHI as a member.');
        }

        if ($wishi->status === 'completed' || $wishi->status === 'cancelled') {
            throw new \DomainException('This WISHI is no longer accepting members.');
        }

        if ($wishi->min_credit_score && (int) $user->credit_score < (int) $wishi->min_credit_score) {
            throw new \DomainException("Your credit score is below the minimum required ({$wishi->min_credit_score}).");
        }

        $maxPerMember = $wishi->max_active_wishis_per_member ?? $user->max_active_wishis;
        if ($maxPerMember && $user->activeWishisCount() >= (int) $maxPerMember) {
            throw new \DomainException("You have reached the maximum number of active WISHIs ({$maxPerMember}).");
        }

        if ($wishi->block_if_missed_payments && $user->hasMissedContributions()) {
            throw new \DomainException('You have missed contributions in another WISHI; new enrolments are blocked.');
        }

        $current = $wishi->activeMembers()->count();
        if ($current >= $wishi->total_members) {
            throw new \DomainException('This WISHI is already at full capacity.');
        }
    }

    /**
     * If the WISHI just became full (still in draft/not started), ping the creator
     * so they know they can start it. Idempotent — only fires once per capacity hit.
     */
    protected function notifyIfJustFilled(Wishi $wishi): void
    {
        if ($wishi->status !== 'draft') {
            return;
        }
        $active = $wishi->activeMembers()->count();
        if ($active < (int) $wishi->total_members) {
            return;
        }

        // Check we haven't already notified this admin for this WISHI recently
        $alreadyNotified = $wishi->creator
            ?->notifications()
            ->where('type', WishiFullNotification::class)
            ->where('data->wishi_id', $wishi->id)
            ->whereNull('read_at')
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $wishi->creator?->notify(new WishiFullNotification($wishi));
    }
}
