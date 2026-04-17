<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContributionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'cycle_id' => $this->cycle_id,
            'wishi_id' => $this->wishi_id,
            'user_id' => $this->user_id,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            // Canonical paid-indicator: `paid_at` is the single source of truth.
            // `status='late'` can mean either "unpaid overdue" or "paid after due",
            // so UIs should use `is_paid` to decide whether to show the "Mark paid" action.
            'is_paid' => $this->paid_at !== null,
            'paid_late' => $this->status === 'late' && $this->paid_at !== null,
            'due_date' => optional($this->due_date)?->toDateString(),
            'paid_at' => optional($this->paid_at)?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            // Cycle context — included only when the relation is eager-loaded,
            // so per-cycle endpoints don't pay for it but aggregate endpoints
            // (like /my-contributions) can expose cycle_number + winner info.
            'cycle' => $this->when($this->relationLoaded('cycle') && $this->cycle, function () {
                return [
                    'id' => $this->cycle->id,
                    'cycle_number' => $this->cycle->cycle_number,
                    'mode' => $this->cycle->mode,
                    'status' => $this->cycle->status,
                    'winner_id' => $this->cycle->winner_id,
                    'selection_method' => $this->cycle->selection_method,
                    'paid_out_at' => optional($this->cycle->paid_out_at)?->toDateString(),
                    'contribution_due_at' => optional($this->cycle->contribution_due_at)?->toIso8601String(),
                ];
            }),
        ];
    }
}
