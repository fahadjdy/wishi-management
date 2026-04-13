<?php

namespace App\Notifications;

use App\Models\Wishi;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WishiCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Wishi $wishi) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $monthly = number_format((float) $this->wishi->monthly_contribution, 2);
        $pool = number_format((float) $this->wishi->monthly_contribution * (int) $this->wishi->total_members, 2);

        return [
            'kind' => 'wishi_created',
            'title' => 'New WISHI available to join',
            'message' => "A new WISHI '{$this->wishi->name}' has been created — ₹{$monthly}/month, {$this->wishi->total_members} seats, pool ₹{$pool}. Open the WISHI page to request to join.",
            'wishi_id' => $this->wishi->id,
            'wishi_uuid' => $this->wishi->uuid,
            'wishi_name' => $this->wishi->name,
            'monthly_contribution' => (float) $this->wishi->monthly_contribution,
            'total_members' => (int) $this->wishi->total_members,
            'duration_months' => (int) $this->wishi->duration_months,
            'start_date' => optional($this->wishi->start_date)?->toDateString(),
            'cycle_type' => $this->wishi->cycle_type,
        ];
    }
}
