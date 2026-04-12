<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PayoutService
{
    public function __construct(
        protected AuditService $audit,
        protected DeferredPayoutService $deferred,
    ) {}

    public function record(Cycle $cycle, User $actor, array $data): Payout
    {
        return DB::transaction(function () use ($cycle, $actor, $data) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if (! $cycle->winner_id) {
                throw new \DomainException('Cycle has no winner; cannot record payout.');
            }
            if ($cycle->paid_out_at) {
                throw new \DomainException('Payout already recorded for this cycle.');
            }

            $payout = Payout::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $cycle->wishi_id,
                'user_id' => $cycle->winner_id,
                'amount' => $cycle->payout_amount ?? $cycle->total_pool,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ]);

            $cycle->update([
                'paid_out_at' => now(),
                'status' => 'completed',
            ]);

            $this->audit->log($cycle->wishi, $actor, 'payout_recorded', "Payout of ₹{$payout->amount} recorded for cycle #{$cycle->cycle_number}", [
                'cycle_id' => $cycle->id,
                'winner_id' => $cycle->winner_id,
                'amount' => (float) $payout->amount,
                'method' => $payout->method,
            ]);

            // If this payout completes the final cycle, release all deferred amounts.
            $wishi = $cycle->wishi()->first();
            if ($wishi && $this->deferred->shouldRelease($wishi)) {
                $count = $this->deferred->releaseAll($wishi, $actor);
                if ($wishi->status !== 'completed') {
                    $wishi->update(['status' => 'completed']);
                    $this->audit->log($wishi, $actor, 'wishi_completed',
                        "WISHI completed. Released {$count} deferred payout(s) to tender winners.",
                        ['deferred_released_count' => $count]);
                }
            }

            return $payout;
        });
    }
}
