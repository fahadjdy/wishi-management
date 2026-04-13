<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Companion to `2026_04_13_120000_fix_contribution_due_dates`. The previous
     * fix only touched `contributions.due_date`; this one rebuilds
     * `cycles.contribution_due_at` so that `cycle_due == contribution_due` again.
     *
     * Algorithm: for every cycle on a not-yet-completed WISHI, recompute the
     * cycle's start date from `wishi.start_date` + (cycle_number - 1) * frequency,
     * and overwrite `contribution_due_at` with that value. Tender open/close
     * windows are also rewritten to the single-day `tender_start_time` →
     * `tender_end_time` semantics on the cycle's own start date.
     */
    public function up(): void
    {
        $cycles = DB::table('cycles as c')
            ->join('wishis as w', 'w.id', '=', 'c.wishi_id')
            ->whereIn('w.status', ['draft', 'planned', 'active'])
            ->select([
                'c.id as cid',
                'c.cycle_number',
                'c.mode',
                'w.start_date',
                'w.cycle_frequency as freq',
                'w.cycle_interval_days as iv',
                'w.tender_start_time as t_open',
                'w.tender_end_time as t_close',
            ])
            ->get();

        foreach ($cycles as $c) {
            if (! $c->start_date) continue;
            $offset = (int) $c->cycle_number - 1;
            $base = strtotime($c->start_date);

            $startTs = match ($c->freq) {
                'daily' => strtotime("+{$offset} days", $base),
                'weekly' => strtotime("+{$offset} weeks", $base),
                'quarterly' => strtotime("+" . (3 * $offset) . " months", $base),
                'yearly' => strtotime("+{$offset} years", $base),
                'custom' => strtotime("+" . ($offset * max(1, (int) ($c->iv ?? 1))) . " days", $base),
                default => strtotime("+{$offset} months", $base),
            };

            $newDue = date('Y-m-d 00:00:00', $startTs);

            $update = ['contribution_due_at' => $newDue];

            if ($c->mode === 'tender') {
                $startDateOnly = date('Y-m-d', $startTs);
                $tOpen = $c->t_open ?: '06:00:00';
                $tClose = $c->t_close ?: '20:00:00';
                $update['tender_opens_at'] = "{$startDateOnly} {$tOpen}";
                $update['tender_closes_at'] = "{$startDateOnly} {$tClose}";
            }

            DB::table('cycles')->where('id', $c->cid)->update($update);
        }
    }

    public function down(): void
    {
        // Non-reversible — original buggy values are not retained.
    }
};
