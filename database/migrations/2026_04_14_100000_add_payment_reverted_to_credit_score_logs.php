<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE credit_score_logs MODIFY COLUMN action ENUM(
            'on_time_payment',
            'early_payment',
            'late_payment',
            'missed_payment',
            'manual_adjust',
            'payment_reverted'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE credit_score_logs SET action = 'manual_adjust' WHERE action = 'payment_reverted'");
        DB::statement("ALTER TABLE credit_score_logs MODIFY COLUMN action ENUM(
            'on_time_payment',
            'early_payment',
            'late_payment',
            'missed_payment',
            'manual_adjust'
        ) NOT NULL");
    }
};
