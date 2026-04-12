<?php

namespace App\Notifications;

use App\Models\Contribution;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public Contribution $contribution) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'kind' => 'payment_reminder',
            'title' => 'Payment due soon',
            'message' => "Your contribution of ₹{$this->contribution->amount} for cycle #{$this->contribution->cycle->cycle_number} is due on {$this->contribution->due_date->toDateString()}.",
            'wishi_id' => $this->contribution->wishi_id,
            'wishi_name' => $this->contribution->wishi?->name,
            'cycle_id' => $this->contribution->cycle_id,
            'amount' => (float) $this->contribution->amount,
            'due_date' => $this->contribution->due_date->toDateString(),
        ];
    }
}
