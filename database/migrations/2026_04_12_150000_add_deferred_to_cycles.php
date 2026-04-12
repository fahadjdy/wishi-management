<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $table->decimal('deferred_amount', 14, 2)->default(0)->after('surplus_recipient_id');
            $table->timestamp('deferred_released_at')->nullable()->after('deferred_amount');
            $table->foreignId('deferred_payout_id')->nullable()->after('deferred_released_at')->constrained('payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $table->dropForeign(['deferred_payout_id']);
            $table->dropColumn(['deferred_amount', 'deferred_released_at', 'deferred_payout_id']);
        });
    }
};
