<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use App\Notifications\WishiStartedNotification;
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
            $wishi = Wishi::create(array_merge($data, [
                'created_by' => $creator->id,
                'status' => 'draft', // Always starts as draft, regardless of requested status
            ]));

            WishiMember::create([
                'wishi_id' => $wishi->id,
                'user_id' => $creator->id,
                'status' => 'active',
                'is_admin' => true,
                'joined_at' => now(),
            ]);

            $this->audit->log($wishi, $creator, 'wishi_created', "WISHI '{$wishi->name}' created", [
                'total_members' => $wishi->total_members,
                'monthly_contribution' => $wishi->monthly_contribution,
                'duration_months' => $wishi->duration_months,
                'cycle_type' => $wishi->cycle_type,
            ]);

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

    public function activate(Wishi $wishi, User $actor): Wishi
    {
        return DB::transaction(function () use ($wishi, $actor) {
            $wishi = Wishi::whereKey($wishi->id)->lockForUpdate()->first();

            if ($wishi->status === 'active') {
                throw new \DomainException('This WISHI is already active.');
            }
            if (in_array($wishi->status, ['completed', 'cancelled'], true)) {
                throw new \DomainException('This WISHI cannot be started from its current state.');
            }

            $active = $wishi->activeMembers()->count();
            $needed = (int) $wishi->total_members;
            if ($active < $needed) {
                $remaining = $needed - $active;
                throw new \DomainException(
                    "WISHI can't start yet — {$active}/{$needed} members have joined. Need {$remaining} more approved member(s) before starting."
                );
            }

            // On activation the planned start_date becomes today's date (concrete, from now on).
            $wishi->update([
                'status' => 'active',
                'start_date' => now()->toDateString(),
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
