<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'credit_score' => (int) $this->credit_score,
            'trust_level' => $this->trust_level,
            'is_admin' => (bool) $this->is_admin,
            'is_locked' => (bool) ($this->locked_until && $this->locked_until->isFuture()),
            'locked_until' => optional($this->locked_until)?->toIso8601String(),
            'failed_login_attempts' => (int) $this->failed_login_attempts,
            'last_login_at' => optional($this->last_login_at)?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'max_active_wishis' => $this->max_active_wishis,
            'created_wishis_count' => $this->createdWishis()->count(),
            'active_memberships_count' => $this->wishiMemberships()->whereIn('status', ['approved', 'active'])->count(),
            'won_count' => $this->wishiMemberships()->where('has_won', true)->count(),
            'missed_payments_count' => $this->contributions()->where('status', 'missed')->count(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
