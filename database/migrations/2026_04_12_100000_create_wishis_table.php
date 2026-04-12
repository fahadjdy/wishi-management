<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishis', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('total_members');
            $table->decimal('monthly_contribution', 12, 2);
            $table->unsignedSmallInteger('duration_months');
            $table->date('start_date');
            $table->unsignedSmallInteger('current_cycle')->default(0);
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            $table->boolean('auto_join')->default(false);
            $table->boolean('require_approval')->default(true);
            $table->enum('winner_selection_mode', ['auto', 'manual'])->default('auto');
            $table->enum('cycle_type', ['random', 'tender', 'hybrid'])->default('random');
            $table->json('hybrid_pattern')->nullable();
            $table->unsignedSmallInteger('min_credit_score')->nullable();
            $table->unsignedSmallInteger('max_active_wishis_per_member')->nullable();
            $table->boolean('block_if_missed_payments')->default(false);
            $table->time('tender_start_time')->nullable();
            $table->time('tender_end_time')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishis');
    }
};
