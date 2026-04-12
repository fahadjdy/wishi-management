<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\User;
use App\Models\Wishi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CycleService
{
    public function __construct(protected AuditService $audit) {}

    /**
     * Compute the start-date of cycle N based on the WISHI's configured frequency.
     * Supports daily / weekly / monthly / quarterly / yearly / custom (every N days).
     */
    protected function startDateForCycle(Wishi $wishi, int $cycleNumber): Carbon
    {
        $base = Carbon::parse($wishi->start_date);
        $offset = $cycleNumber - 1;

        return match ($wishi->cycle_frequency) {
            'daily' => $base->copy()->addDays($offset),
            'weekly' => $base->copy()->addWeeks($offset),
            'quarterly' => $base->copy()->addMonthsNoOverflow(3 * $offset),
            'yearly' => $base->copy()->addYearsNoOverflow($offset),
            'custom' => $base->copy()->addDays($offset * max(1, (int) ($wishi->cycle_interval_days ?? 1))),
            default => $base->copy()->addMonthsNoOverflow($offset),
        };
    }

    public function resolveCycleMode(Wishi $wishi, int $cycleNumber): string
    {
        if ($wishi->cycle_type === 'random') {
            return 'random';
        }
        if ($wishi->cycle_type === 'tender') {
            return 'tender';
        }
        // hybrid
        $pattern = $wishi->hybrid_pattern;
        if (! is_array($pattern) || empty($pattern)) {
            return 'random';
        }
        $idx = ($cycleNumber - 1) % count($pattern);
        $value = strtolower((string) ($pattern[$idx] ?? 'random'));
        return in_array($value, ['random', 'tender']) ? $value : 'random';
    }

    public function createNextCycle(Wishi $wishi): Cycle
    {
        return DB::transaction(function () use ($wishi) {
            $next = ((int) $wishi->current_cycle) + 1;
            if ($next > $wishi->duration_months) {
                throw new \DomainException('All cycles for this WISHI are already complete.');
            }

            // Cycle #1 is always the "organizer payout" — the admin receives the first
            // pool. Members contribute but no one bids and no one else wins cycle #1.
            // We force mode=random, pre-assign winner, and skip the selection step.
            $isAdminCycle = ($next === 1);

            $mode = $isAdminCycle ? 'random' : $this->resolveCycleMode($wishi, $next);
            $startDate = $this->startDateForCycle($wishi, $next);
            // "Grace window" for contributions before the cycle matures. For fast cadences
            // (daily / weekly) we shrink this so the cycle can actually close on time.
            $graceDays = match ($wishi->cycle_frequency) {
                'daily' => 0,
                'weekly' => 2,
                'custom' => min(2, max(0, (int) ($wishi->cycle_interval_days ?? 1) - 1)),
                default => 7,
            };
            $dueDate = $startDate->copy()->addDays($graceDays);

            $tenderOpens = null;
            $tenderCloses = null;
            if ($mode === 'tender') {
                // Bidding window: opens `bidding_window_days` before the cycle's start date.
                $windowDays = max(0, (int) ($wishi->bidding_window_days ?? 3));
                $tenderOpens = $startDate->copy()->subDays($windowDays)
                    ->setTimeFromTimeString($wishi->tender_start_time ?: '10:00:00');
                $tenderCloses = $startDate->copy()
                    ->setTimeFromTimeString($wishi->tender_end_time ?: '20:00:00');
                if ($tenderCloses->lessThanOrEqualTo($tenderOpens)) {
                    $tenderCloses = $tenderOpens->copy()->addDay();
                }
            }

            $payload = [
                'wishi_id' => $wishi->id,
                'cycle_number' => $next,
                'mode' => $mode,
                'status' => 'contribution_open',
                'total_pool' => $wishi->totalPool(),
                'contribution_due_at' => $dueDate,
                'tender_opens_at' => $tenderOpens,
                'tender_closes_at' => $tenderCloses,
            ];

            if ($isAdminCycle) {
                // Pre-assign admin as winner of cycle #1; no selection UI needed.
                $payload['winner_id'] = $wishi->created_by;
                $payload['payout_amount'] = $wishi->totalPool();
                $payload['selection_method'] = 'organizer_payout';
                $payload['selected_at'] = now();
            }

            $cycle = Cycle::create($payload);

            $this->bootstrapContributions($cycle, $wishi, $dueDate->toDateString());

            $wishi->update(['current_cycle' => $next]);
            $this->audit->log($wishi, null, 'cycle_opened',
                $isAdminCycle
                    ? "Cycle #1 opened — organizer payout to admin"
                    : "Cycle #{$next} opened ({$mode})",
                [
                    'cycle_number' => $next,
                    'mode' => $mode,
                    'organizer_cycle' => $isAdminCycle,
                ]
            );

            return $cycle;
        });
    }

    protected function bootstrapContributions(Cycle $cycle, Wishi $wishi, string $dueDate): void
    {
        $members = $wishi->members()
            ->whereIn('status', ['approved', 'active'])
            ->get();

        foreach ($members as $member) {
            Contribution::firstOrCreate(
                ['cycle_id' => $cycle->id, 'user_id' => $member->user_id],
                [
                    'wishi_id' => $wishi->id,
                    'amount' => $wishi->monthly_contribution,
                    'status' => 'pending',
                    'due_date' => $dueDate,
                ]
            );
        }
    }

    public function advanceToNextCycle(Wishi $wishi, User $actor): Cycle
    {
        return DB::transaction(function () use ($wishi, $actor) {
            $current = $wishi->cycles()->where('cycle_number', $wishi->current_cycle)->first();
            if ($current && $current->status !== 'completed') {
                throw new \DomainException('Current cycle must be completed before advancing.');
            }

            if ((int) $wishi->current_cycle >= (int) $wishi->duration_months) {
                $wishi->update(['status' => 'completed']);
                $this->audit->log($wishi, $actor, 'wishi_completed', 'All cycles completed; WISHI marked as completed.');
                throw new \DomainException('All cycles for this WISHI are complete.');
            }

            return $this->createNextCycle($wishi);
        });
    }
}
