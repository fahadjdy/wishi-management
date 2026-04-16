<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp_number' => $this->whatsapp_number,
            'avatar_url' => $this->avatar_url,
            'credit_score' => (int) $this->credit_score,
            'trust_level' => $this->trust_level,
            'max_active_wishis' => $this->max_active_wishis,
            'is_admin' => (bool) $this->is_admin,
            'is_locked' => (bool) ($this->locked_until && $this->locked_until->isFuture()),
            'last_login_at' => optional($this->last_login_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
