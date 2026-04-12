<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_score_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('wishi_id')->nullable()->constrained('wishis')->nullOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('cycles')->nullOnDelete();
            $table->enum('action', ['on_time_payment', 'early_payment', 'late_payment', 'missed_payment', 'manual_adjust']);
            $table->smallInteger('points');
            $table->unsignedSmallInteger('score_before');
            $table->unsignedSmallInteger('score_after');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_score_logs');
    }
};
