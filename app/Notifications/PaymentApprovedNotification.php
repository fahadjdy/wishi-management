<?php

namespace App\Notifications;

use App\Models\Contribution;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public Contribution $contribution) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $wishi = $this->contribution->wishi;
        $cycleNumber = $this->contribution->cycle?->cycle_number;
        $amount = number_format((float) $this->contribution->amount, 2);
        $late = $this->contribution->status === 'late';

        return [
            'kind' => 'payment_approved',
            'title' => $late ? 'Late payment approved' : 'Payment approved',
            'message' => "Your ₹{$amount} payment for '{$wishi?->name}' (cycle #{$cycleNumber}) has been received and approved by the admin.",
            'wishi_id' => $wishi?->id,
            'wishi_uuid' => $wishi?->uuid,
            'wishi_name' => $wishi?->name,
            'cycle_id' => $this->contribution->cycle_id,
            'cycle_number' => $cycleNumber,
            'contribution_id' => $this->contribution->id,
            'amount' => (float) $this->contribution->amount,
            'status' => $this->contribution->status,
            'paid_at' => optional($this->contribution->paid_at)?->toIso8601String(),
        ];
    }
}
