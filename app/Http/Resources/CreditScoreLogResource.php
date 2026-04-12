<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CreditScoreLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'wishi_id' => $this->wishi_id,
            'cycle_id' => $this->cycle_id,
            'action' => $this->action,
            'points' => (int) $this->points,
            'score_before' => (int) $this->score_before,
            'score_after' => (int) $this->score_after,
            'reason' => $this->reason,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
