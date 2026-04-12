<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishi_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishi_id')->constrained('wishis')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'active', 'removed', 'left'])->default('pending');
            $table->boolean('is_admin')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->boolean('has_won')->default(false);
            $table->unsignedSmallInteger('won_in_cycle')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['wishi_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['wishi_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishi_members');
    }
};
