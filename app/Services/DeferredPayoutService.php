<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wishi;
use Illuminate\Support\Facades\DB;

class DeferredPayoutService
{
    public function __construct(protected AuditService $audit) {}

    /**
     * After a WISHI is fully paid out, release every pending deferred amount
     * to the tender winners who earned it. Safe to call multiple times
     * (idempotent — skips cycles already released).
     */
    public function releaseAll(Wishi $wishi, ?User $actor = null): int
    {
        return DB::transaction(function () use ($wishi, $actor) {
            $cycles = Cycle::where('wishi_id', $wishi->id)
                ->where('deferred_amount', '>', 0)
                ->whereNull('deferred_released_at')
                ->whereNotNull('winner_id')
                ->orderBy('cycle_number')
                ->lockForUpdate()
                ->get();

            $released = 0;
            foreach ($cycles as $cycle) {
                $payout = Payout::create([
                    'cycle_id' => $cycle->id,
                    'wishi_id' => $wishi->id,
                    'user_id' => $cycle->winner_id,
                    'amount' => $cycle->deferred_amount,
                    'method' => 'bank_transfer',
                    'reference' => 'DEFERRED-' . strtoupper(bin2hex(random_bytes(4))),
                    'notes' => "Deferred tender surplus from cycle #{$cycle->cycle_number} released on WISHI completion.",
                    'paid_at' => now(),
                ]);

                $cycle->update([
                    'deferred_released_at' => now(),
                    'deferred_payout_id' => $payout->id,
                ]);

                $this->audit->log($wishi, $actor, 'deferred_payout_released',
                    "Released deferred ₹{$cycle->deferred_amount} to user #{$cycle->winner_id} from cycle #{$cycle->cycle_number}",
                    [
                        'cycle_id' => $cycle->id,
                        'cycle_number' => $cycle->cycle_number,
                        'winner_id' => $cycle->winner_id,
                        'amount' => (float) $cycle->deferred_amount,
                        'payout_id' => $payout->id,
                    ]
                );
                $released++;
            }

            return $released;
        });
    }

    /**
     * Decide whether a WISHI is fully paid and deferred amounts can now release.
     */
    public function shouldRelease(Wishi $wishi): bool
    {
        $totalCycles = (int) $wishi->duration_months;
        $completedAndPaid = Cycle::where('wishi_id', $wishi->id)
            ->where('status', 'completed')
            ->whereNotNull('paid_out_at')
            ->count();
        return $completedAndPaid >= $totalCycles;
    }

    public function totalPending(Wishi $wishi): float
    {
        return (float) Cycle::where('wishi_id', $wishi->id)
            ->where('deferred_amount', '>', 0)
            ->whereNull('deferred_released_at')
            ->sum('deferred_amount');
    }
}
