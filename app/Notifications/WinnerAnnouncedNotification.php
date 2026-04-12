<?php

namespace App\Notifications;

use App\Models\Cycle;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WinnerAnnouncedNotification extends Notification
{
    use Queueable;

    public function __construct(public Cycle $cycle, public bool $isWinner = false) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'kind' => 'winner_announced',
            'title' => $this->isWinner ? '🎉 You won this cycle!' : 'Cycle winner announced',
            'message' => $this->isWinner
                ? "You are the winner of cycle #{$this->cycle->cycle_number}. Payout: ₹{$this->cycle->payout_amount}."
                : "Cycle #{$this->cycle->cycle_number} winner has been selected.",
            'wishi_id' => $this->cycle->wishi_id,
            'wishi_name' => $this->cycle->wishi?->name,
            'cycle_id' => $this->cycle->id,
            'cycle_number' => $this->cycle->cycle_number,
            'winner_id' => $this->cycle->winner_id,
            'amount' => $this->cycle->payout_amount !== null ? (float) $this->cycle->payout_amount : null,
        ];
    }
}
