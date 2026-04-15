<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use App\Notifications\WishiCreatedNotification;
use App\Notifications\WishiStartedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class WishiService
{
    public function __construct(
        protected AuditService $audit,
        protected CycleService $cycles,
    ) {}

    public function create(User $creator, array $data): Wishi
    {
        return DB::transaction(function () use ($creator, $data) {
            // duration_months always equals total_members (one cycle per member,
            // including cycle #1 organizer payout to admin). Not asked of admin.
            $data['duration_months'] = (int) $data['total_members'];

            $wishi = Wishi::create(array_merge($data, [
                'created_by' => $creator->id,
                // New WISHIs go straight to `planned` so members can discover and
                // join immediately based on `require_approval`. `draft` is reserved
                // for admin-initiated archive/pause; never the default on creation.
                'status' => 'planned',
            ]));

            // NOTE: admin is intentionally NOT added as a member. Admins manage the WISHI,
            // they do not contribute, bid, or win. `total_members` refers exclusively to
            // non-admin participants.

            $this->audit->log($wishi, $creator, 'wishi_created', "WISHI '{$wishi->name}' created", [
                'total_members' => $wishi->total_members,
                'monthly_contribution' => $wishi->monthly_contribution,
                'duration_months' => $wishi->duration_months,
                'cycle_type' => $wishi->cycle_type,
            ]);

            // Broadcast to every non-admin, non-creator user so they can discover
            // and request to join. Platform admins and the wishi creator are excluded.
            $recipients = User::where('is_admin', false)
                ->where('id', '!=', $creator->id)
                ->where(function ($q) {
                    $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                })
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new WishiCreatedNotification($wishi));
            }

            return $wishi->fresh();
        });
    }

    public function updateSettings(Wishi $wishi, User $actor, array $data): Wishi
    {
        return DB::transaction(function () use ($wishi, $actor, $data) {
            // Once active, admin cannot silently flip back to draft or change member count
            $before = $wishi->only(array_keys($data));
            $wishi->update($data);
            $this->audit->log($wishi, $actor, 'settings_updated', 'WISHI settings updated', [
                'before' => $before,
                'after' => $wishi->only(array_keys($data)),
            ]);
            return $wishi->fresh();
        });
    }

    /**
     * Move a WISHI from `draft` (admin-only) to `planned` (public — visible in
     * the Discover list, members can request to join). Admins can still edit
     * planned WISHIs until activation.
     */
    public function publish(Wishi $wishi, User $actor): Wishi
    {
        return DB::transaction(function () use ($wishi, $actor) {
            $wishi = Wishi::whereKey($wishi->id)->lockForUpdate()->first();
            if ($wishi->status !== 'draft') {
                throw new \DomainException('Only draft WISHIs can be published.');
            }
            $wishi->update(['status' => 'planned']);
            $this->audit->log($wishi, $actor, 'wishi_published', "WISHI '{$wishi->name}' published for discovery");

            // Broadcast again on publish in case admin created this WISHI long before
            // making it public — notifications from `create` may have grown stale.
            $recipients = User::where('is_admin', false)
                ->where('id', '!=', $wishi->created_by)
                ->where(function ($q) {
                    $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                })
                ->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new WishiCreatedNotification($wishi));
            }

            return $wishi->fresh();
        });
    }

    public function activate(Wishi $wishi, User $actor): Wishi
    {
        return DB::transaction(function () use ($wishi, $actor) {
            $wishi = Wishi::whereKey($wishi->id)->lockForUpdate()->first();

            if ($wishi->status === 'active') {
                throw new \DomainException('This WISHI is already active.');
            }
            if (! in_array($wishi->status, ['draft', 'planned'], true)) {
                throw new \DomainException('This WISHI cannot be started from its current state.');
            }

            $active = $wishi->activeMembers()->count();
            $needed = $wishi->memberCapacity();
            if ($active < $needed) {
                $remaining = $needed - $active;
                throw new \DomainException(
                    "WISHI can't start yet — {$active}/{$needed} members have joined. Need {$remaining} more approved member(s) before starting."
                );
            }

            // WISHI cannot open before its planned start_date. Admin must wait until
            // the date arrives before activating.
            $plannedStart = $wishi->start_date ? Carbon::parse($wishi->start_date)->startOfDay() : null;
            if ($plannedStart && $plannedStart->isFuture()) {
                $days = (int) now()->startOfDay()->diffInDays($plannedStart);
                throw new \DomainException(
                    "WISHI cannot open before its planned start date ({$plannedStart->toDateString()}). It can be started in {$days} day(s)."
                );
            }

            // Preserve the planned start_date if it matches today; otherwise stamp today.
            $activationDate = $plannedStart && $plannedStart->isSameDay(now())
                ? $plannedStart->toDateString()
                : now()->toDateString();

            $wishi->update([
                'status' => 'active',
                'start_date' => $activationDate,
                'current_cycle' => 0,
            ]);

            $cycle = $this->cycles->createNextCycle($wishi);

            $this->audit->log($wishi, $actor, 'wishi_activated', 'WISHI started by admin after full enrolment', [
                'start_date' => $wishi->start_date->toDateString(),
                'active_members' => $active,
                'first_cycle_due' => optional($cycle->contribution_due_at)?->toIso8601String(),
            ]);

            // Notify every approved/active member that the WISHI has started.
            $recipients = $wishi->activeMembers()
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter();

            Notification::send($recipients, new WishiStartedNotification($wishi, $cycle));

            return $wishi->fresh();
        });
    }
}
