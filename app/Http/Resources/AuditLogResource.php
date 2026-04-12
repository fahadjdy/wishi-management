<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'wishi_id' => $this->wishi_id,
            'user_id' => $this->user_id,
            'action' => $this->action,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
        ];
    }
}
