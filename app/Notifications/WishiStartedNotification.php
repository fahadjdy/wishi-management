<?php

namespace App\Notifications;

use App\Models\Cycle;
use App\Models\Wishi;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WishiStartedNotification extends Notification
{
    use Queueable;

    public function __construct(public Wishi $wishi, public Cycle $firstCycle) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'kind' => 'wishi_started',
            'title' => '🎉 WISHI started — first cycle open',
            'message' => "'{$this->wishi->name}' is now active. Your first contribution of ₹{$this->wishi->monthly_contribution} is due on " . optional($this->firstCycle->contribution_due_at)?->toDateString() . '.',
            'wishi_id' => $this->wishi->id,
            'wishi_uuid' => $this->wishi->uuid,
            'wishi_name' => $this->wishi->name,
            'cycle_id' => $this->firstCycle->id,
            'cycle_number' => $this->firstCycle->cycle_number,
            'amount' => (float) $this->wishi->monthly_contribution,
            'due_date' => optional($this->firstCycle->contribution_due_at)?->toDateString(),
        ];
    }
}
