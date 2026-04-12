<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Payout;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PayoutService
{
    public function __construct(
        protected AuditService $audit,
        protected DeferredPayoutService $deferred,
    ) {}

    public function record(Cycle $cycle, User $actor, array $data): array
    {
        return DB::transaction(function () use ($cycle, $actor, $data) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if (! $cycle->winner_id && $cycle->winners_count === 0) {
                throw new \DomainException('Cycle has no winner; cannot record payout.');
            }
            if ($cycle->paid_out_at) {
                throw new \DomainException('Payout already recorded for this cycle.');
            }

            $payouts = [];

            // Multi-winner tender: one payout per winning bid.
            if ($cycle->winners_count > 1) {
                $winningBids = Tender::where('cycle_id', $cycle->id)
                    ->where('is_winning_bid', true)
                    ->with('user')
                    ->get();

                foreach ($winningBids as $bid) {
                    $payouts[] = Payout::create([
                        'cycle_id' => $cycle->id,
                        'wishi_id' => $cycle->wishi_id,
                        'user_id' => $bid->user_id,
                        'amount' => $bid->bid_amount,
                        'method' => $data['method'] ?? null,
                        'reference' => ($data['reference'] ?? '') . ' · W' . $bid->user_id,
                        'notes' => $data['notes'] ?? null,
                        'paid_at' => now(),
                    ]);
                }
            } else {
                // Single-winner (random draw or single tender bid).
                $payouts[] = Payout::create([
                    'cycle_id' => $cycle->id,
                    'wishi_id' => $cycle->wishi_id,
                    'user_id' => $cycle->winner_id,
                    'amount' => $cycle->payout_amount ?? $cycle->total_pool,
                    'method' => $data['method'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'paid_at' => now(),
                ]);
            }

            $cycle->update([
                'paid_out_at' => now(),
                'status' => 'completed',
            ]);

            $totalPaid = array_sum(array_map(fn ($p) => (float) $p->amount, $payouts));
            $this->audit->log($cycle->wishi, $actor, 'payout_recorded',
                sprintf('Cycle #%d payout of ₹%s recorded (%d winner%s)',
                    $cycle->cycle_number, number_format($totalPaid, 2), count($payouts), count($payouts) !== 1 ? 's' : ''),
                [
                    'cycle_id' => $cycle->id,
                    'winners_count' => count($payouts),
                    'total_amount' => $totalPaid,
                    'admin_topup_amount' => (float) $cycle->admin_topup_amount,
                    'method' => $data['method'] ?? null,
                ]
            );

            // Auto-release deferred on WISHI completion
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

            return $payouts;
        });
    }
}
