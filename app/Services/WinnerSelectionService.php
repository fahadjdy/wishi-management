<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Tender;
use App\Models\User;
use App\Models\WishiMember;
use Illuminate\Support\Facades\DB;

class WinnerSelectionService
{
    public function __construct(protected AuditService $audit) {}

    public function eligibleMembers(Cycle $cycle)
    {
        return $cycle->wishi->members()
            ->whereIn('status', ['approved', 'active'])
            ->where('has_won', false)
            ->with('user')
            ->get();
    }

    public function selectRandomWinner(Cycle $cycle, User $actor): Cycle
    {
        return DB::transaction(function () use ($cycle, $actor) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if ($cycle->winner_id) {
                throw new \DomainException('Winner already selected for this cycle.');
            }
            $eligible = $this->eligibleMembers($cycle);
            if ($eligible->isEmpty()) {
                throw new \DomainException('No eligible members remain to win.');
            }

            $seedBytes = random_bytes(32);
            $seed = bin2hex($seedBytes);
            $index = unpack('N', substr($seedBytes, 0, 4))[1] % $eligible->count();
            $winnerMember = $eligible->values()->get($index);

            $cycle->update([
                'winner_id' => $winnerMember->user_id,
                'selection_method' => 'auto_random',
                'selection_seed' => $seed,
                'selected_at' => now(),
                'payout_amount' => $cycle->total_pool,
                'status' => 'selection_pending',
            ]);

            $this->markMemberWon($winnerMember, $cycle);

            $this->audit->log($cycle->wishi, $actor, 'winner_selected', "Random draw winner selected for cycle #{$cycle->cycle_number}", [
                'cycle_id' => $cycle->id,
                'cycle_number' => $cycle->cycle_number,
                'winner_id' => $winnerMember->user_id,
                'method' => 'auto_random',
                'seed' => $seed,
                'eligible_count' => $eligible->count(),
            ]);

            return $cycle->fresh();
        });
    }

    public function selectTenderWinner(Cycle $cycle, User $actor): Cycle
    {
        return DB::transaction(function () use ($cycle, $actor) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if ($cycle->winner_id) {
                throw new \DomainException('Winner already selected for this cycle.');
            }
            if ($cycle->mode !== 'tender') {
                throw new \DomainException('This cycle is not a tender cycle.');
            }
            if ($cycle->tender_closes_at && $cycle->tender_closes_at->isFuture()) {
                throw new \DomainException('Tender window has not closed yet.');
            }

            $bids = $cycle->tenders()->orderBy('bid_amount', 'asc')->orderBy('placed_at', 'asc')->get();
            if ($bids->isEmpty()) {
                throw new \DomainException('No bids placed in this tender; admin must select manually or re-run.');
            }

            $winningBid = $bids->first();
            $member = WishiMember::where('wishi_id', $cycle->wishi_id)
                ->where('user_id', $winningBid->user_id)
                ->first();
            if (! $member || $member->has_won) {
                throw new \DomainException('Winning bidder is not eligible.');
            }

            $surplus = max(0, (float) $cycle->total_pool - (float) $winningBid->bid_amount);

            // Tender rule: surplus (pool − winning bid) is DEFERRED for the winner.
            // They receive `winning_bid` now; the surplus is paid after all cycles complete.
            $cycle->update([
                'winner_id' => $winningBid->user_id,
                'winning_bid' => $winningBid->bid_amount,
                'surplus' => $surplus,
                'deferred_amount' => $surplus,
                'surplus_action' => $surplus > 0 ? 'deferred_to_winner' : null,
                'selection_method' => 'auto_tender',
                'selected_at' => now(),
                'payout_amount' => $winningBid->bid_amount,
                'status' => 'selection_pending',
            ]);

            $cycle->tenders()->update(['is_winning_bid' => false]);
            $winningBid->update(['is_winning_bid' => true]);

            $this->markMemberWon($member, $cycle);

            $this->audit->log($cycle->wishi, $actor, 'winner_selected', "Tender winner selected for cycle #{$cycle->cycle_number}", [
                'cycle_id' => $cycle->id,
                'cycle_number' => $cycle->cycle_number,
                'winner_id' => $winningBid->user_id,
                'method' => 'auto_tender',
                'winning_bid' => (float) $winningBid->bid_amount,
                'surplus' => $surplus,
                'bid_count' => $bids->count(),
            ]);

            return $cycle->fresh();
        });
    }

    public function manualSelectWinner(Cycle $cycle, int $userId, User $actor, ?string $reason = null): Cycle
    {
        return DB::transaction(function () use ($cycle, $userId, $actor, $reason) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if ($cycle->winner_id) {
                throw new \DomainException('Winner already selected for this cycle.');
            }
            $member = WishiMember::where('wishi_id', $cycle->wishi_id)
                ->where('user_id', $userId)
                ->whereIn('status', ['approved', 'active'])
                ->where('has_won', false)
                ->first();
            if (! $member) {
                throw new \DomainException('Selected user is not an eligible member.');
            }

            $cycle->update([
                'winner_id' => $userId,
                'selection_method' => 'manual',
                'selected_at' => now(),
                'payout_amount' => $cycle->total_pool,
                'status' => 'selection_pending',
            ]);

            $this->markMemberWon($member, $cycle);

            $this->audit->log($cycle->wishi, $actor, 'winner_selected', "Manual winner override for cycle #{$cycle->cycle_number}", [
                'cycle_id' => $cycle->id,
                'cycle_number' => $cycle->cycle_number,
                'winner_id' => $userId,
                'method' => 'manual',
                'reason' => $reason,
            ]);

            return $cycle->fresh();
        });
    }

    protected function markMemberWon(WishiMember $member, Cycle $cycle): void
    {
        $member->update([
            'has_won' => true,
            'won_in_cycle' => $cycle->cycle_number,
        ]);
    }
}
