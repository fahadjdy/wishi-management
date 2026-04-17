<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use App\Notifications\MemberJoinedNotification;
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
            // `wishi_members` carries a UNIQUE(wishi_id, user_id) index that spans
            // soft-deleted rows too, so a user who left+rejoins must reuse their
            // existing row — creating a fresh one would trigger a duplicate-key
            // error. `withTrashed()` is required to see previously-left rows.
            $existing = WishiMember::withTrashed()
                ->where('wishi_id', $wishi->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing && ! $existing->trashed() && in_array($existing->status, ['pending', 'approved', 'active'])) {
                throw new \DomainException('You are already a member or have a pending request.');
            }

            // Rejoin path: restore the soft-deleted row and reset per-membership
            // state (token, win history, admin-invite flag) so it behaves like a
            // fresh join. Status / joined_at are (re)set just below.
            if ($existing && $existing->trashed()) {
                $existing->restore();
            }

            $status = $wishi->require_approval ? 'pending' : 'approved';
            $member = $existing
                ? tap($existing)->update([
                    'status' => $status,
                    'joined_at' => $status === 'approved' ? now() : null,
                    'token_no' => null,
                    'has_won' => false,
                    'won_in_cycle' => null,
                    'invited_by_admin' => false,
                ])
                : WishiMember::create([
                    'wishi_id' => $wishi->id,
                    'user_id' => $user->id,
                    'status' => $status,
                    'joined_at' => $status === 'approved' ? now() : null,
                ]);

            if ($status === 'approved') {
                $this->assignTokenIfMissing($member);
            }

            $this->audit->log($wishi, $user, 'member_join_requested', "User #{$user->id} requested to join", [
                'user_id' => $user->id,
                'auto_approved' => $status === 'approved',
            ]);

            // Notify admin: either a new join request (needs approval) or someone
            // just auto-joined (no approval needed).
            $wishi->creator?->notify(new MemberJoinedNotification(
                $wishi,
                $user,
                $status === 'approved' ? 'joined' : 'requested',
            ));

            // If auto-approved, also check if we just hit capacity
            if ($status === 'approved') {
                $this->notifyIfJustFilled($wishi);
            }

            return $member;
        });
    }

    /**
     * Member voluntarily cancels their own join request / withdraws from the
     * WISHI. Allowed only while the WISHI hasn't activated — once cycle #1
     * opens, leaving requires admin removal (and only in draft/planned per
     * WishiMemberPolicy::remove). Self-initiated join requests AND
     * admin-invitations-the-user-accepted both use this path.
     */
    public function cancelOwnMembership(Wishi $wishi, User $user): void
    {
        if (! in_array($wishi->status, ['draft', 'planned'], true)) {
            throw new \DomainException('You can only cancel your membership while the WISHI has not started yet.');
        }

        $member = WishiMember::where('wishi_id', $wishi->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->first();
        if (! $member) {
            throw new \DomainException('You have no active membership or pending request on this WISHI.');
        }

        DB::transaction(function () use ($member, $wishi, $user) {
            $wasPending = $member->status === 'pending';
            $member->update(['status' => 'removed']);
            $member->delete();
            $this->audit->log($wishi, $user, $wasPending ? 'join_request_cancelled' : 'member_self_removed',
                $wasPending
                    ? "User #{$user->id} cancelled their join request"
                    : "User #{$user->id} left the WISHI before activation",
                ['user_id' => $user->id, 'was_status' => $wasPending ? 'pending' : 'approved']
            );

            // Inform the admin so they know a seat just freed up.
            $wishi->creator?->notify(new MemberStatusNotification(
                $wishi,
                $wasPending ? 'request_cancelled' : 'left',
                $wasPending
                    ? "{$user->name} cancelled their join request."
                    : "{$user->name} left the WISHI before it started."
            ));
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
        if (! in_array($wishi->status, ['planned', 'active', 'draft'], true)) {
            throw new \DomainException('This WISHI is no longer accepting members.');
        }
        if ($wishi->activeMembers()->count() >= $wishi->memberCapacity()) {
            throw new \DomainException('This WISHI is already at full capacity. Admin occupies seat #1, so only (total_members − 1) members can be invited.');
        }

        return DB::transaction(function () use ($wishi, $user, $actor) {
            // Same unique-index gotcha as requestJoin() — include trashed rows
            // so a previously-removed user can be re-invited without duplicate-
            // key errors.
            $existing = WishiMember::withTrashed()
                ->where('wishi_id', $wishi->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing && ! $existing->trashed() && in_array($existing->status, ['pending', 'approved', 'active'], true)) {
                throw new \DomainException('User is already a member or has a pending request/invite.');
            }

            if ($existing && $existing->trashed()) {
                $existing->restore();
            }

            $row = $existing
                ? tap($existing)->update([
                    'status' => 'pending',
                    'invited_by_admin' => true,
                    'joined_at' => null,
                    'token_no' => null,
                    'has_won' => false,
                    'won_in_cycle' => null,
                ])
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
        if ($member->wishi->activeMembers()->count() >= $member->wishi->memberCapacity()) {
            throw new \DomainException('WISHI is already full.');
        }
        return DB::transaction(function () use ($member, $user) {
            $member->update(['status' => 'approved', 'joined_at' => now()]);
            $this->assignTokenIfMissing($member);
            $this->audit->log($member->wishi, $user, 'invite_accepted', 'User accepted admin invitation',
                ['user_id' => $user->id]);
            $member->wishi->creator?->notify(new MemberJoinedNotification(
                $member->wishi, $user, 'accepted_invite'
            ));
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
            if ($current >= $member->wishi->memberCapacity()) {
                throw new \DomainException('WISHI is already at full capacity.');
            }
            $member->update([
                'status' => 'approved',
                'joined_at' => $member->joined_at ?? now(),
            ]);
            $this->assignTokenIfMissing($member);
            $this->audit->log($member->wishi, $actor, 'member_approved', "Member #{$member->user_id} approved", [
                'member_user_id' => $member->user_id,
            ]);

            // Notify the member themselves
            $member->user?->notify(new MemberStatusNotification($member->wishi, 'approved'));

            // Notify the admin that a new member is in.
            if ($member->user) {
                $member->wishi->creator?->notify(new MemberJoinedNotification(
                    $member->wishi, $member->user, 'joined'
                ));
            }

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

    /**
     * Assign the next sequential token_no (1..n) for an approved member.
     * No-op if the member already has a token. Locks the wishi row to
     * serialize concurrent approvals so tokens stay dense and unique.
     *
     * `withTrashed()` is essential: the `wishi_members_wishi_token_unique`
     * index on (wishi_id, token_no) spans soft-deleted rows too, so if a past
     * member who left still has their token_no set on a trashed row, a fresh
     * `max()` over active rows alone can hand out a colliding number. Reading
     * max across trashed rows guarantees we always pick a genuinely free slot.
     */
    protected function assignTokenIfMissing(WishiMember $member): void
    {
        if ($member->token_no) {
            return;
        }
        DB::transaction(function () use ($member) {
            Wishi::whereKey($member->wishi_id)->lockForUpdate()->first();
            // Token #1 is reserved for the admin/organizer (cycle-#1 payout).
            // Real members therefore start at token #2 and count upward.
            $max = (int) WishiMember::withTrashed()
                ->where('wishi_id', $member->wishi_id)
                ->max('token_no');
            $next = $max < 2 ? 2 : $max + 1;
            $member->update(['token_no' => $next]);
        });
    }

    protected function guardEligibility(Wishi $wishi, User $user): void
    {
        if ((int) $wishi->created_by === (int) $user->id) {
            throw new \DomainException('Admins cannot join their own WISHI as a member.');
        }

        if (! in_array($wishi->status, ['planned', 'active', 'draft'], true)) {
            throw new \DomainException('This WISHI is no longer accepting members.');
        }

        $current = $wishi->activeMembers()->count();
        if ($current >= $wishi->memberCapacity()) {
            throw new \DomainException('This WISHI is already at full capacity.');
        }
    }

    /**
     * If the WISHI just became full (still in draft/not started), ping the creator
     * so they know they can start it. Idempotent — only fires once per capacity hit.
     * Also auto-rejects any leftover pending rows (both member-initiated and
     * admin-invited) since the seat is no longer available to them.
     */
    protected function notifyIfJustFilled(Wishi $wishi): void
    {
        $active = $wishi->activeMembers()->count();
        if ($active < $wishi->memberCapacity()) {
            return;
        }

        // Capacity is hit → any still-pending rows are now orphaned.
        $this->autoRejectPendingOnFull($wishi);

        if ($wishi->status !== 'draft') {
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

    /**
     * Sweep pending join-requests + admin-invites on a WISHI that has reached
     * capacity. Each orphaned row is marked `removed` + soft-deleted, the user
     * gets a `rejected_full` notification, and an audit line records the sweep.
     * Safe to call more than once — idempotent (no pending rows = no-op).
     */
    public function autoRejectPendingOnFull(Wishi $wishi): int
    {
        $pending = WishiMember::where('wishi_id', $wishi->id)
            ->where('status', 'pending')
            ->with('user')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($pending, $wishi) {
            foreach ($pending as $member) {
                $member->update(['status' => 'removed']);
                $member->delete();

                $this->audit->log($wishi, null, 'pending_auto_rejected_wishi_full',
                    "Pending request from user #{$member->user_id} auto-rejected — WISHI reached capacity",
                    [
                        'member_user_id' => $member->user_id,
                        'invited_by_admin' => (bool) $member->invited_by_admin,
                    ]
                );

                $member->user?->notify(new MemberStatusNotification($wishi, 'rejected_full'));
            }
        });

        return $pending->count();
    }
}
