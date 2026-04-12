<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishi_members', function (Blueprint $table) {
            // Distinguishes admin-initiated invites from member-initiated join requests.
            // When true + status='pending' the member sees "Accept / Decline" on their
            // dashboard. When false + status='pending' the admin sees an approval queue.
            $table->boolean('invited_by_admin')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('wishi_members', function (Blueprint $table) {
            $table->dropColumn('invited_by_admin');
        });
    }
};
