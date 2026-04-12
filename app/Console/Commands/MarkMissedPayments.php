<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Services\CreditScoreService;
use Illuminate\Console\Command;

class MarkMissedPayments extends Command
{
    protected $signature = 'wishi:mark-missed {--grace-days=14}';
    protected $description = 'After grace period, mark late contributions as missed and apply heavier credit penalty.';

    public function handle(CreditScoreService $creditScore): int
    {
        $grace = (int) $this->option('grace-days');
        $cutoff = now()->subDays($grace)->startOfDay();
        $missed = Contribution::where('status', 'late')
            ->whereDate('due_date', '<', $cutoff)
            ->with(['user', 'wishi', 'cycle'])
            ->get();

        foreach ($missed as $c) {
            $c->update(['status' => 'missed']);
            $creditScore->updateScore($c->user, 'missed_payment', $c->wishi, $c->cycle, null, "Auto-marked missed after {$grace}-day grace");
        }

        $this->info("Marked {$missed->count()} contributions as missed.");
        return self::SUCCESS;
    }
}
