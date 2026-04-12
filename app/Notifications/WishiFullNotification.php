<?php

namespace App\Notifications;

use App\Models\Wishi;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WishiFullNotification extends Notification
{
    use Queueable;

    public function __construct(public Wishi $wishi) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'kind' => 'wishi_full',
            'title' => '✅ WISHI ready to start',
            'message' => "'{$this->wishi->name}' has reached full capacity ({$this->wishi->total_members} members). You can now start the WISHI and open cycle #1.",
            'wishi_id' => $this->wishi->id,
            'wishi_uuid' => $this->wishi->uuid,
            'wishi_name' => $this->wishi->name,
        ];
    }
}
