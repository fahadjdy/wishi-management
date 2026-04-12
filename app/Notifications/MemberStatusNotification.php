<?php

namespace App\Notifications;

use App\Models\Wishi;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MemberStatusNotification extends Notification
{
    use Queueable;

    public function __construct(public Wishi $wishi, public string $status, public ?string $note = null) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $titles = [
            'approved' => 'Membership approved',
            'rejected' => 'Membership rejected',
            'removed' => 'Removed from WISHI',
        ];
        return [
            'kind' => 'member_status',
            'title' => $titles[$this->status] ?? 'Membership update',
            'message' => "Your membership in '{$this->wishi->name}' was {$this->status}." . ($this->note ? ' ' . $this->note : ''),
            'wishi_id' => $this->wishi->id,
            'wishi_name' => $this->wishi->name,
            'status' => $this->status,
        ];
    }
}
