<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishi_id')->constrained('wishis')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_number');
            $table->enum('mode', ['random', 'tender']);
            $table->enum('status', ['pending', 'contribution_open', 'bidding_open', 'selection_pending', 'completed'])->default('pending');
            $table->decimal('total_pool', 14, 2)->nullable();
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('winning_bid', 12, 2)->nullable();
            $table->decimal('surplus', 12, 2)->default(0);
            $table->enum('surplus_action', ['distribute', 'reserve', 'admin_adjust', 'bonus', 'deferred_to_winner'])->nullable();
            $table->foreignId('surplus_recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('selection_method', ['auto_random', 'auto_tender', 'manual'])->nullable();
            $table->string('selection_seed', 128)->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->decimal('payout_amount', 14, 2)->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->dateTime('contribution_due_at')->nullable();
            $table->dateTime('tender_opens_at')->nullable();
            $table->dateTime('tender_closes_at')->nullable();
            $table->timestamps();

            $table->unique(['wishi_id', 'cycle_number']);
            $table->index(['status']);
            $table->index(['winner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycles');
    }
};
