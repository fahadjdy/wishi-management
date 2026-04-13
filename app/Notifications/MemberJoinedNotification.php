<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Wishi;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MemberJoinedNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $event  one of: 'requested', 'joined', 'accepted_invite'
     */
    public function __construct(
        public Wishi $wishi,
        public User $member,
        public string $event = 'joined',
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $titles = [
            'requested' => 'New join request',
            'joined' => 'New member joined',
            'accepted_invite' => 'Invitation accepted',
        ];
        $messages = [
            'requested' => "{$this->member->name} has requested to join '{$this->wishi->name}'. Review and approve or reject.",
            'joined' => "{$this->member->name} has joined '{$this->wishi->name}'.",
            'accepted_invite' => "{$this->member->name} accepted your invitation to '{$this->wishi->name}'.",
        ];

        return [
            'kind' => 'member_joined',
            'event' => $this->event,
            'title' => $titles[$this->event] ?? 'Member update',
            'message' => $messages[$this->event] ?? "{$this->member->name} membership updated in '{$this->wishi->name}'.",
            'wishi_id' => $this->wishi->id,
            'wishi_uuid' => $this->wishi->uuid,
            'wishi_name' => $this->wishi->name,
            'member_user_id' => $this->member->id,
            'member_name' => $this->member->name,
        ];
    }
}
