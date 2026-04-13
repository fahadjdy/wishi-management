<?php

namespace App\Http\Resources;

use App\Models\Contribution;
use Illuminate\Http\Resources\Json\JsonResource;

class WishiMemberResource extends JsonResource
{
    public function toArray($request): array
    {
        $stats = $this->paymentStats();

        return [
            'id' => $this->id,
            'wishi_id' => $this->wishi_id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'is_admin' => (bool) $this->is_admin,
            'token_no' => $this->token_no,
            'has_won' => (bool) $this->has_won,
            'won_in_cycle' => $this->won_in_cycle,
            'joined_at' => optional($this->joined_at)?->toIso8601String(),
            'on_time_rate' => $stats['rate'],
            'on_time_count' => $stats['on_time'],
            'settled_count' => $stats['total'],
            'user' => new UserSummaryResource($this->whenLoaded('user')),
        ];
    }

    /**
     * On-time rate % over settled contributions (paid/late/missed) for this
     * member in this WISHI. Returns null while nothing has been settled yet.
     */
    protected function paymentStats(): array
    {
        $row = Contribution::where('wishi_id', $this->wishi_id)
            ->where('user_id', $this->user_id)
            ->whereIn('status', ['paid', 'late', 'missed'])
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS on_time, COUNT(*) AS total")
            ->first();

        $total = (int) ($row->total ?? 0);
        $onTime = (int) ($row->on_time ?? 0);

        return [
            'on_time' => $onTime,
            'total' => $total,
            'rate' => $total ? round(($onTime / $total) * 100, 1) : null,
        ];
    }
}
