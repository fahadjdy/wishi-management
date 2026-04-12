<?php

namespace App\Http\Resources;

use App\Models\Wishi;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'credit_score' => (int) $this->credit_score,
            'trust_level' => $this->trust_level,
            'active_wishis_count' => $this->when(
                $request->routeIs('*.members.*') || $request->routeIs('*members*') || str_contains($request->path(), 'members'),
                fn () => $this->activeWishisCount()
            ),
        ];
    }
}
