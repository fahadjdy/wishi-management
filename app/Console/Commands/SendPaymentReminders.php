<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Notifications\PaymentReminderNotification;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'wishi:payment-reminders {--days=3}';
    protected $description = 'Send reminders for contributions due within N days.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $window = now()->addDays($days)->endOfDay();
        $due = Contribution::where('status', 'pending')
            ->whereDate('due_date', '<=', $window)
            ->whereDate('due_date', '>=', now()->startOfDay())
            ->with(['user', 'wishi', 'cycle'])
            ->get();

        foreach ($due as $c) {
            $c->user->notify(new PaymentReminderNotification($c));
        }

        $this->info("Sent {$due->count()} payment reminders.");
        return self::SUCCESS;
    }
}
