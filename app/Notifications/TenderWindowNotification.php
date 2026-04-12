<?php

namespace App\Notifications;

use App\Models\Cycle;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TenderWindowNotification extends Notification
{
    use Queueable;

    public function __construct(public Cycle $cycle, public string $event = 'opens') {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $msg = $this->event === 'opens'
            ? "Tender window for cycle #{$this->cycle->cycle_number} is now open."
            : "Tender window for cycle #{$this->cycle->cycle_number} closes soon.";
        return [
            'kind' => 'tender_window',
            'title' => $this->event === 'opens' ? 'Bidding open' : 'Bidding closing soon',
            'message' => $msg,
            'wishi_id' => $this->cycle->wishi_id,
            'wishi_name' => $this->cycle->wishi?->name,
            'cycle_id' => $this->cycle->id,
            'cycle_number' => $this->cycle->cycle_number,
            'tender_closes_at' => optional($this->cycle->tender_closes_at)?->toIso8601String(),
        ];
    }
}
