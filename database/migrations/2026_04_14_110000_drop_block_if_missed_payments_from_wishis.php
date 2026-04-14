<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishis', function (Blueprint $table) {
            $table->dropColumn('block_if_missed_payments');
        });
    }

    public function down(): void
    {
        Schema::table('wishis', function (Blueprint $table) {
            $table->boolean('block_if_missed_payments')->default(false)->after('max_active_wishis_per_member');
        });
    }
};
