<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishis', function (Blueprint $table) {
            $table->enum('cycle_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'])
                ->default('monthly')
                ->after('duration_months');
            $table->unsignedSmallInteger('cycle_interval_days')->nullable()->after('cycle_frequency');
            $table->string('cycle_day', 20)->nullable()->after('cycle_interval_days');
            $table->unsignedTinyInteger('bidding_window_days')->default(3)->after('tender_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('wishis', function (Blueprint $table) {
            $table->dropColumn(['cycle_frequency', 'cycle_interval_days', 'cycle_day', 'bidding_window_days']);
        });
    }
};
