<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles')->cascadeOnDelete();
            $table->foreignId('wishi_id')->constrained('wishis')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('bid_amount', 12, 2);
            $table->boolean('is_winning_bid')->default(false);
            $table->timestamp('placed_at');
            $table->string('placed_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'user_id']);
            $table->index(['cycle_id', 'bid_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
