<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TenderResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $cycle = $this->cycle ?? $this->resource->cycle;
        $windowClosed = $cycle && $cycle->tender_closes_at && $cycle->tender_closes_at->isPast();
        $isAdmin = $user && $cycle && (int) $cycle->wishi->created_by === (int) $user->id;
        $isOwn = $user && (int) $this->user_id === (int) $user->id;
        $reveal = $windowClosed || $isAdmin || $isOwn;

        return [
            'id' => $this->id,
            'cycle_id' => $this->cycle_id,
            'user_id' => $reveal ? $this->user_id : null,
            'bid_amount' => $reveal ? (float) $this->bid_amount : null,
            'is_winning_bid' => (bool) $this->is_winning_bid,
            'placed_at' => optional($this->placed_at)?->toIso8601String(),
            'user' => $reveal ? new UserSummaryResource($this->whenLoaded('user')) : null,
        ];
    }
}
