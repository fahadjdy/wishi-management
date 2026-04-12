<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class TenderService
{
    public function __construct(protected AuditService $audit) {}

    public function placeBid(Cycle $cycle, User $user, float $bidAmount): Tender
    {
        return DB::transaction(function () use ($cycle, $user, $bidAmount) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if ($cycle->mode !== 'tender') {
                throw new \DomainException('Bidding is not enabled for this cycle.');
            }
            if (! $cycle->isBiddingOpen()) {
                throw new \DomainException('Bidding window is not open.');
            }
            if ($bidAmount <= 0) {
                throw new \DomainException('Bid amount must be greater than 0.');
            }
            if ($bidAmount > (float) $cycle->total_pool) {
                throw new \DomainException('Bid amount cannot exceed the total pool (₹' . $cycle->total_pool . ').');
            }

            $member = $cycle->wishi->members()
                ->where('user_id', $user->id)
                ->whereIn('status', ['approved', 'active'])
                ->first();
            if (! $member) {
                throw new \DomainException('You are not an active member of this WISHI.');
            }
            if ($member->has_won) {
                throw new \DomainException('You have already won a cycle in this WISHI.');
            }

            $tender = Tender::updateOrCreate(
                ['cycle_id' => $cycle->id, 'user_id' => $user->id],
                [
                    'wishi_id' => $cycle->wishi_id,
                    'bid_amount' => $bidAmount,
                    'placed_at' => now(),
                    'placed_ip' => Request::ip(),
                ]
            );

            $this->audit->log($cycle->wishi, $user, 'bid_placed', "Bid placed in cycle #{$cycle->cycle_number}", [
                'cycle_id' => $cycle->id,
                'user_id' => $user->id,
                'amount' => $bidAmount,
            ]);

            return $tender;
        });
    }
}
