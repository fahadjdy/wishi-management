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
            'rejected_full' => 'WISHI is now full',
            'invited' => 'Invited to WISHI',
            'request_cancelled' => 'Join request cancelled',
            'left' => 'Member left WISHI',
        ];
        $messages = [
            'rejected_full' => "'{$this->wishi->name}' has filled up before your request could be approved. The seat went to another member.",
        ];
        $defaultMessage = "Your membership in '{$this->wishi->name}' was {$this->status}.";
        return [
            'kind' => 'member_status',
            'title' => $titles[$this->status] ?? 'Membership update',
            'message' => ($messages[$this->status] ?? $defaultMessage) . ($this->note ? ' ' . $this->note : ''),
            'wishi_id' => $this->wishi->id,
            'wishi_name' => $this->wishi->name,
            'status' => $this->status,
        ];
    }
}
