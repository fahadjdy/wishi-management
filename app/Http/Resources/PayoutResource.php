<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PayoutResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'cycle_id' => $this->cycle_id,
            'wishi_id' => $this->wishi_id,
            'user_id' => $this->user_id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'paid_at' => optional($this->paid_at)?->toIso8601String(),
        ];
    }
}
