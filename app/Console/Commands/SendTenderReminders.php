<?php

namespace App\Console\Commands;

use App\Models\Cycle;
use App\Notifications\TenderWindowNotification;
use Illuminate\Console\Command;

class SendTenderReminders extends Command
{
    protected $signature = 'wishi:tender-reminders';
    protected $description = 'Notify members when a tender window opens or is about to close.';

    public function handle(): int
    {
        $now = now();

        $opening = Cycle::where('mode', 'tender')
            ->where('status', 'bidding_open')
            ->whereBetween('tender_opens_at', [$now->copy()->subMinutes(10), $now->copy()->addMinutes(10)])
            ->with('wishi.activeMembers.user')
            ->get();

        $closing = Cycle::where('mode', 'tender')
            ->where('status', 'bidding_open')
            ->whereBetween('tender_closes_at', [$now->copy()->addHours(1), $now->copy()->addHours(1)->addMinutes(30)])
            ->with('wishi.activeMembers.user')
            ->get();

        $sent = 0;
        foreach ($opening as $cycle) {
            foreach ($cycle->wishi->activeMembers as $m) {
                $m->user?->notify(new TenderWindowNotification($cycle, 'opens'));
                $sent++;
            }
        }
        foreach ($closing as $cycle) {
            foreach ($cycle->wishi->activeMembers as $m) {
                $m->user?->notify(new TenderWindowNotification($cycle, 'closes_soon'));
                $sent++;
            }
        }

        $this->info("Sent {$sent} tender reminders.");
        return self::SUCCESS;
    }
}
