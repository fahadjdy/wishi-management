<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishi_members', function (Blueprint $table) {
            $table->unsignedSmallInteger('token_no')->nullable()->after('invited_by_admin');
        });

        // Backfill: assign sequential token_no (1..n) to existing approved/active
        // members per wishi, ordered by joined_at (null-safe), then id.
        $wishiIds = DB::table('wishi_members')
            ->whereIn('status', ['approved', 'active'])
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('wishi_id');

        foreach ($wishiIds as $wishiId) {
            $rows = DB::table('wishi_members')
                ->where('wishi_id', $wishiId)
                ->whereIn('status', ['approved', 'active'])
                ->whereNull('deleted_at')
                ->orderByRaw('joined_at IS NULL, joined_at ASC')
                ->orderBy('id')
                ->get(['id']);

            $token = 1;
            foreach ($rows as $row) {
                DB::table('wishi_members')
                    ->where('id', $row->id)
                    ->update(['token_no' => $token++]);
            }
        }

        Schema::table('wishi_members', function (Blueprint $table) {
            $table->unique(['wishi_id', 'token_no'], 'wishi_members_wishi_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wishi_members', function (Blueprint $table) {
            $table->dropUnique('wishi_members_wishi_token_unique');
            $table->dropColumn('token_no');
        });
    }
};
