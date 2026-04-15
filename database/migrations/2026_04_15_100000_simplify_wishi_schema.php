<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishis', function (Blueprint $table) {
            if (Schema::hasColumn('wishis', 'min_credit_score')) {
                $table->dropColumn('min_credit_score');
            }
            if (Schema::hasColumn('wishis', 'max_active_wishis_per_member')) {
                $table->dropColumn('max_active_wishis_per_member');
            }
            if (Schema::hasColumn('wishis', 'tender_start_time')) {
                $table->dropColumn('tender_start_time');
            }
            if (Schema::hasColumn('wishis', 'tender_end_time')) {
                $table->dropColumn('tender_end_time');
            }
            if (Schema::hasColumn('wishis', 'bidding_window_days')) {
                $table->dropColumn('bidding_window_days');
            }
        });

        Schema::table('wishis', function (Blueprint $table) {
            if (! Schema::hasColumn('wishis', 'wishi_opening_time')) {
                $table->time('wishi_opening_time')->default('00:00:00')->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wishis', function (Blueprint $table) {
            if (Schema::hasColumn('wishis', 'wishi_opening_time')) {
                $table->dropColumn('wishi_opening_time');
            }
            $table->unsignedSmallInteger('min_credit_score')->nullable();
            $table->unsignedSmallInteger('max_active_wishis_per_member')->nullable();
            $table->time('tender_start_time')->nullable();
            $table->time('tender_end_time')->nullable();
            $table->unsignedSmallInteger('bidding_window_days')->nullable();
        });
    }
};
