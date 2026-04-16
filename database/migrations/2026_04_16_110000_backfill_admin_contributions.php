<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: the admin (organizer) contributes every cycle alongside every
 * real member (FLOW.md §6 — pool = monthly × total_members, admin holds
 * seat #1). `CycleService::bootstrapContributions` started creating the
 * admin's Contribution row on 2026-04-15 (commit bcfce03). Any cycle
 * bootstrapped before that commit is missing the admin's row, which in
 * turn breaks the member dashboard's "Next month total" and makes the
 * admin look like they're not a member of their own WISHI.
 *
 * For every currently-open cycle in an active WISHI, ensure the admin has
 * a contribution row. Amount = wishi.monthly_contribution. Due date = the
 * cycle's own due date (we mirror the real members' rows for that cycle
 * rather than recomputing grace, so the admin row is date-consistent
 * with the rest of the cycle).
 *
 * We intentionally **do not** backfill completed cycles — those pools have
 * already been paid out and the admin's contribution is presumed settled
 * off-system. Creating `pending` rows for historical cycles would surface
 * fake late payments and unfairly ding the admin's credit score.
 */
return new class extends Migration
{
    public function up(): void
    {
        $candidates = DB::table('cycles')
            ->join('wishis', 'wishis.id', '=', 'cycles.wishi_id')
            ->whereIn('cycles.status', ['contribution_open', 'bidding_open', 'selection_pending'])
            ->whereNotNull('wishis.created_by')
            ->select([
                'cycles.id as cycle_id',
                'cycles.wishi_id',
                'wishis.created_by as admin_id',
                'wishis.monthly_contribution',
            ])
            ->get();

        foreach ($candidates as $row) {
            $exists = DB::table('contributions')
                ->where('cycle_id', $row->cycle_id)
                ->where('user_id', $row->admin_id)
                ->exists();
            if ($exists) {
                continue;
            }

            // Mirror the due_date of any existing contribution on the same
            // cycle so the admin row lines up with real members' rows.
            $peerDue = DB::table('contributions')
                ->where('cycle_id', $row->cycle_id)
                ->orderBy('id')
                ->value('due_date');

            DB::table('contributions')->insert([
                'cycle_id' => $row->cycle_id,
                'wishi_id' => $row->wishi_id,
                'user_id' => $row->admin_id,
                'amount' => $row->monthly_contribution,
                'status' => 'pending',
                'due_date' => $peerDue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-reversible — we cannot distinguish backfilled admin rows from
        // legitimately-created ones after the fact.
    }
};
