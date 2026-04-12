<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Services\CreditScoreService;
use Illuminate\Console\Command;

class CheckPayments extends Command
{
    protected $signature = 'wishi:check-payments';
    protected $description = 'Mark overdue pending contributions as late and apply credit score penalty.';

    public function handle(CreditScoreService $creditScore): int
    {
        $today = now()->startOfDay();
        $overdue = Contribution::where('status', 'pending')
            ->whereDate('due_date', '<', $today)
            ->with(['user', 'wishi', 'cycle'])
            ->get();

        $count = 0;
        foreach ($overdue as $c) {
            $c->update(['status' => 'late']);
            $creditScore->updateScore($c->user, 'late_payment', $c->wishi, $c->cycle, null, 'Auto-marked late by scheduler');
            $count++;
        }

        $this->info("Marked {$count} contributions as late.");
        return self::SUCCESS;
    }
}
