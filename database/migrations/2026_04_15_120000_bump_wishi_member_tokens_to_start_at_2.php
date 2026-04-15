<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rule (effective 2026-04-15, FLOW.md §4): token #1 is reserved for the
 * admin/organizer; real members start at token #2. Existing rows were
 * numbered 1..N under the old rule — shift every token_no by +1 so old
 * data aligns with the new display rule (admin virtual #1, members #2+).
 *
 * Safe to run: processed highest-first to avoid unique-index collisions
 * on (wishi_id, token_no).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Update highest token numbers first so we never collide with a
            // row we haven't touched yet.
            DB::table('wishi_members')
                ->whereNotNull('token_no')
                ->orderByDesc('token_no')
                ->get(['id', 'token_no'])
                ->each(function ($row) {
                    DB::table('wishi_members')
                        ->where('id', $row->id)
                        ->update(['token_no' => $row->token_no + 1]);
                });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('wishi_members')
                ->whereNotNull('token_no')
                ->where('token_no', '>', 1)
                ->orderBy('token_no')
                ->get(['id', 'token_no'])
                ->each(function ($row) {
                    DB::table('wishi_members')
                        ->where('id', $row->id)
                        ->update(['token_no' => $row->token_no - 1]);
                });
        });
    }
};
