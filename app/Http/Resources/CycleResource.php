<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CycleResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $isAdmin = $user && $this->wishi && (int) $this->wishi->created_by === (int) $user->id;

        return [
            'id' => $this->id,
            'wishi_id' => $this->wishi_id,
            'cycle_number' => (int) $this->cycle_number,
            'mode' => $this->mode,
            'status' => $this->status,
            'total_pool' => (float) $this->total_pool,
            'winner_id' => $this->winner_id,
            'winning_bid' => $this->winning_bid !== null ? (float) $this->winning_bid : null,
            'surplus' => (float) $this->surplus,
            'surplus_action' => $this->surplus_action,
            'surplus_recipient_id' => $this->surplus_recipient_id,
            'deferred_amount' => (float) $this->deferred_amount,
            'deferred_released_at' => optional($this->deferred_released_at)?->toIso8601String(),
            'deferred_payout_id' => $this->deferred_payout_id,
            'deferred_pending' => (float) $this->deferred_amount > 0 && ! $this->deferred_released_at,
            'selection_method' => $this->selection_method,
            'selection_seed' => $this->when($isAdmin, $this->selection_seed),
            'selected_at' => optional($this->selected_at)?->toIso8601String(),
            'payout_amount' => $this->payout_amount !== null ? (float) $this->payout_amount : null,
            'paid_out_at' => optional($this->paid_out_at)?->toIso8601String(),
            'contribution_due_at' => optional($this->contribution_due_at)?->toIso8601String(),
            'tender_opens_at' => optional($this->tender_opens_at)?->toIso8601String(),
            'tender_closes_at' => optional($this->tender_closes_at)?->toIso8601String(),
            'is_bidding_open' => $this->isBiddingOpen(),
            'winner' => new UserSummaryResource($this->whenLoaded('winner')),
        ];
    }
}
