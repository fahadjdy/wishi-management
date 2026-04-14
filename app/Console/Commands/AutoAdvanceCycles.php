<?php

namespace App\Console\Commands;

use App\Models\Wishi;
use App\Services\CycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AutoAdvanceCycles extends Command
{
    protected $signature = 'wishi:auto-advance';
    protected $description = 'Auto-open the next cycle for any active WISHI whose current cycle is completed and whose next cycle\'s scheduled start date has arrived.';

    public function handle(CycleService $cycles): int
    {
        $advanced = 0;
        $wishis = Wishi::where('status', 'active')->get();

        foreach ($wishis as $wishi) {
            if ((int) $wishi->current_cycle >= (int) $wishi->duration_months) {
                continue;
            }
            $current = $wishi->cycles()->where('cycle_number', $wishi->current_cycle)->first();
            if (! $current || $current->status !== 'completed') {
                continue;
            }

            $nextNumber = (int) $wishi->current_cycle + 1;
            $nextScheduled = $this->scheduledStartForCycle($wishi, $nextNumber);
            if ($nextScheduled->isFuture()) {
                continue;
            }

            try {
                $cycles->createNextCycle($wishi->fresh());
                $advanced++;
                $this->info("Advanced WISHI #{$wishi->id} ({$wishi->name}) → cycle #{$nextNumber}");
            } catch (\Throwable $e) {
                $this->warn("Failed to advance WISHI #{$wishi->id}: {$e->getMessage()}");
            }
        }

        $this->info("Auto-advanced {$advanced} cycle(s).");
        return self::SUCCESS;
    }

    protected function scheduledStartForCycle(Wishi $wishi, int $n): Carbon
    {
        $base = Carbon::parse($wishi->start_date);
        $offset = $n - 1;

        return match ($wishi->cycle_frequency) {
            'daily' => $base->copy()->addDays($offset),
            'weekly' => $base->copy()->addWeeks($offset),
            'quarterly' => $base->copy()->addMonthsNoOverflow(3 * $offset),
            'yearly' => $base->copy()->addYearsNoOverflow($offset),
            'custom' => $base->copy()->addDays($offset * max(1, (int) ($wishi->cycle_interval_days ?? 1))),
            default => $base->copy()->addMonthsNoOverflow($offset),
        };
    }
}
