<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            // When admins pick multiple winning tender bids whose sum exceeds the pool,
            // the admin personally covers the shortfall. Tracked per-cycle for transparency.
            $table->decimal('admin_topup_amount', 14, 2)->default(0)->after('deferred_payout_id');
            $table->foreignId('admin_topup_by_user_id')->nullable()->after('admin_topup_amount')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('winners_count')->default(0)->after('admin_topup_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $table->dropForeign(['admin_topup_by_user_id']);
            $table->dropColumn(['admin_topup_amount', 'admin_topup_by_user_id', 'winners_count']);
        });
    }
};
