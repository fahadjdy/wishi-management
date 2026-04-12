<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WishiResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $isAdmin = $user && (int) $this->created_by === (int) $user->id;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'created_by' => $this->created_by,
            'is_admin' => $isAdmin,
            'total_members' => (int) $this->total_members,
            'monthly_contribution' => (float) $this->monthly_contribution,
            'total_pool' => (float) $this->totalPool(),
            'duration_months' => (int) $this->duration_months,
            'start_date' => optional($this->start_date)?->toDateString(),
            'current_cycle' => (int) $this->current_cycle,
            'status' => $this->status,
            'auto_join' => (bool) $this->auto_join,
            'require_approval' => (bool) $this->require_approval,
            'winner_selection_mode' => $this->winner_selection_mode,
            'cycle_type' => $this->cycle_type,
            'hybrid_pattern' => $this->hybrid_pattern,
            'min_credit_score' => $this->min_credit_score,
            'max_active_wishis_per_member' => $this->max_active_wishis_per_member,
            'block_if_missed_payments' => (bool) $this->block_if_missed_payments,
            'tender_start_time' => $this->tender_start_time,
            'tender_end_time' => $this->tender_end_time,
            'members_count' => $this->whenCounted('members'),
            'active_members_count' => $this->whenCounted('activeMembers'),
            'pending_members_count' => $this->members()->where('status', 'pending')->count(),
            'seats_remaining' => max(0, (int) $this->total_members - $this->activeMembers()->count()),
            'is_full' => (int) $this->total_members <= $this->activeMembers()->count(),
            'can_start' => $this->status === 'draft' && (int) $this->total_members <= $this->activeMembers()->count(),
            'cycles_completed' => $this->cycles()->where('status', 'completed')->count(),
            'cycles_remaining' => max(0, (int) $this->duration_months - $this->cycles()->where('status', 'completed')->count()),
            'tender_cycles_count' => $this->computeTenderCount(),
            'auto_cycles_count' => $this->computeAutoCount(),
            'active_tender_cycle' => $this->activeTenderSnapshot(),
            'total_surplus' => (float) $this->cycles()->sum('surplus'),
            'unhandled_surplus' => (float) $this->cycles()->whereNotNull('surplus')->where('surplus', '>', 0)->whereNull('surplus_action')->sum('surplus'),
            'deferred_pending_total' => (float) $this->cycles()->where('deferred_amount', '>', 0)->whereNull('deferred_released_at')->sum('deferred_amount'),
            'deferred_released_total' => (float) $this->cycles()->where('deferred_amount', '>', 0)->whereNotNull('deferred_released_at')->sum('deferred_amount'),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }

    protected function computeTenderCount(): int
    {
        if ($this->cycle_type === 'random') return 0;
        if ($this->cycle_type === 'tender') return (int) $this->duration_months;
        // hybrid: count tender steps in pattern over duration
        $pattern = is_array($this->hybrid_pattern) ? $this->hybrid_pattern : [];
        if (! $pattern) return 0;
        $count = 0;
        for ($n = 1; $n <= (int) $this->duration_months; $n++) {
            $step = strtolower((string) ($pattern[($n - 1) % count($pattern)] ?? 'random'));
            if ($step === 'tender') $count++;
        }
        return $count;
    }

    protected function computeAutoCount(): int
    {
        return max(0, (int) $this->duration_months - $this->computeTenderCount());
    }

    protected function activeTenderSnapshot(): ?array
    {
        $cycle = $this->cycles()
            ->where('mode', 'tender')
            ->whereIn('status', ['bidding_open', 'selection_pending'])
            ->orderByDesc('cycle_number')
            ->first();
        if (! $cycle) return null;

        return [
            'cycle_id' => $cycle->id,
            'cycle_number' => $cycle->cycle_number,
            'status' => $cycle->status,
            'total_pool' => (float) $cycle->total_pool,
            'winning_bid' => $cycle->winning_bid !== null ? (float) $cycle->winning_bid : null,
            'surplus' => (float) $cycle->surplus,
            'surplus_action' => $cycle->surplus_action,
            'tender_closes_at' => optional($cycle->tender_closes_at)?->toIso8601String(),
            'bid_count' => $cycle->tenders()->count(),
            'lowest_bid' => (float) ($cycle->tenders()->min('bid_amount') ?? 0),
        ];
    }
}
