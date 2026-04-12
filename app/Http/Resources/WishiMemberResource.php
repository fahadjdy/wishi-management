<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WishiMemberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'wishi_id' => $this->wishi_id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'is_admin' => (bool) $this->is_admin,
            'has_won' => (bool) $this->has_won,
            'won_in_cycle' => $this->won_in_cycle,
            'joined_at' => optional($this->joined_at)?->toIso8601String(),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
        ];
    }
}
