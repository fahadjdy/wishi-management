<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Historically `CycleService` added a grace window (up to 7 days) on top of each
     * cycle's start date to compute `contributions.due_date`. That pushed monthly
     * due dates to ~37 days, violating the "one cycle period" rule. We now use the
     * cycle's `contribution_due_at` (which was set to start + grace) as the source
     * of truth and rewrite every pending/late contribution's `due_date` to match
     * the cycle's actual `start_date` by subtracting the grace that was baked in.
     *
     * For the common monthly case: subtract 7 days. For weekly: 2. Daily: 0.
     * Already-paid contributions keep their historical due_date for audit fidelity.
     */
    public function up(): void
    {
        $rows = DB::table('contributions')
            ->join('cycles', 'cycles.id', '=', 'contributions.cycle_id')
            ->join('wishis', 'wishis.id', '=', 'contributions.wishi_id')
            ->whereIn('contributions.status', ['pending', 'late'])
            ->select([
                'contributions.id as cid',
                'contributions.due_date as old_due',
                'wishis.cycle_frequency as freq',
                'wishis.cycle_interval_days as iv',
            ])
            ->get();

        foreach ($rows as $r) {
            $grace = match ($r->freq) {
                'daily' => 0,
                'weekly' => 2,
                'custom' => min(2, max(0, (int) ($r->iv ?? 1) - 1)),
                default => 7,
            };
            if ($grace === 0 || ! $r->old_due) {
                continue;
            }
            $new = date('Y-m-d', strtotime($r->old_due . " -{$grace} days"));
            DB::table('contributions')->where('id', $r->cid)->update(['due_date' => $new]);
        }
    }

    public function down(): void
    {
        // Non-reversible (we don't retain the original grace-inflated date).
    }
};
