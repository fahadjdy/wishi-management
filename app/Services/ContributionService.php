<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\User;
use App\Notifications\PaymentApprovedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContributionService
{
    public function __construct(
        protected CreditScoreService $creditScore,
        protected AuditService $audit,
    ) {}

    public function recordPayment(Contribution $contribution, User $actor, array $data): Contribution
    {
        return DB::transaction(function () use ($contribution, $actor, $data) {
            $contribution = Contribution::whereKey($contribution->id)->lockForUpdate()->first();
            if (in_array($contribution->status, ['paid'], true)) {
                throw new \DomainException('Contribution already marked as paid.');
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            $due = Carbon::parse($contribution->due_date)->endOfDay();

            $status = 'paid';
            $action = 'on_time_payment';
            if ($paidAt->lessThan(Carbon::parse($contribution->due_date)->subDays(2)->endOfDay())) {
                $action = 'early_payment';
            } elseif ($paidAt->greaterThan($due)) {
                $status = 'late';
                $action = 'late_payment';
            }

            $contribution->update([
                'status' => $status,
                'paid_at' => $paidAt,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->creditScore->updateScore($contribution->user, $action, $contribution->wishi, $contribution->cycle);

            $this->audit->log($contribution->wishi, $actor, 'contribution_recorded', "Contribution paid by user #{$contribution->user_id}", [
                'cycle_id' => $contribution->cycle_id,
                'user_id' => $contribution->user_id,
                'amount' => (float) $contribution->amount,
                'status' => $status,
            ]);

            $this->maybeOpenSelection($contribution->cycle->fresh());

            $fresh = $contribution->fresh(['user', 'wishi', 'cycle']);
            $fresh->user?->notify(new PaymentApprovedNotification($fresh));

            return $fresh;
        });
    }

    public function maybeOpenSelection(Cycle $cycle): void
    {
        $pending = $cycle->contributions()->whereIn('status', ['pending'])->count();
        if ($pending > 0) {
            return;
        }
        if ($cycle->status === 'contribution_open') {
            $newStatus = $cycle->mode === 'tender' ? 'bidding_open' : 'selection_pending';
            $cycle->update(['status' => $newStatus]);
        }
    }

    /**
     * Undo an admin's "Mark as paid" action. Reverses the credit score delta that
     * was applied when the payment was recorded, clears the paid_at stamp, and
     * rolls the cycle back to contribution_open if it had auto-advanced.
     *
     * Blocked once the cycle has a winner or has been paid out — at that point
     * the payment is financially committed and must not be silently rewritten.
     */
    public function revertPayment(Contribution $contribution, User $actor): Contribution
    {
        return DB::transaction(function () use ($contribution, $actor) {
            $contribution = Contribution::whereKey($contribution->id)->lockForUpdate()->first();

            if (! $contribution->paid_at) {
                throw new \DomainException('This contribution is not marked paid — nothing to undo.');
            }

            $cycle = Cycle::whereKey($contribution->cycle_id)->lockForUpdate()->first();

            if ($cycle->paid_out_at) {
                throw new \DomainException('Cannot undo — this cycle has already been paid out.');
            }
            if ($cycle->winner_id && (int) $cycle->cycle_number !== 1) {
                throw new \DomainException('Cannot undo — a winner has already been selected for this cycle.');
            }

            // Recompute which credit action was originally applied so we can
            // reverse exactly the same delta.
            $originalPaidAt = Carbon::parse($contribution->paid_at);
            $dueEod = Carbon::parse($contribution->due_date)->endOfDay();
            $earlyCutoff = Carbon::parse($contribution->due_date)->subDays(2)->endOfDay();

            $originalAction = 'on_time_payment';
            if ($originalPaidAt->lessThan($earlyCutoff)) {
                $originalAction = 'early_payment';
            } elseif ($originalPaidAt->greaterThan($dueEod)) {
                $originalAction = 'late_payment';
            }
            $reversePoints = -1 * (CreditScoreService::POINTS[$originalAction] ?? 0);

            // Status after revert: 'late' if today is past the due date, else 'pending'.
            $newStatus = now()->greaterThan($dueEod) ? 'late' : 'pending';
            $contribution->update([
                'status' => $newStatus,
                'paid_at' => null,
                'payment_method' => null,
                'payment_reference' => null,
                'notes' => null,
            ]);

            $this->creditScore->updateScore(
                $contribution->user,
                'payment_reverted',
                $contribution->wishi,
                $cycle,
                $reversePoints,
                "Revert of {$originalAction} for cycle #{$cycle->cycle_number}",
            );

            // If the cycle had auto-advanced because all members were paid, roll
            // it back to contribution_open so the admin's state matches reality.
            if (in_array($cycle->status, ['selection_pending', 'bidding_open'], true)) {
                $cycle->update(['status' => 'contribution_open']);
            }

            $this->audit->log($contribution->wishi, $actor, 'contribution_reverted', "Payment reverted for user #{$contribution->user_id}", [
                'cycle_id' => $contribution->cycle_id,
                'user_id' => $contribution->user_id,
                'amount' => (float) $contribution->amount,
                'original_paid_at' => $originalPaidAt->toIso8601String(),
                'original_action' => $originalAction,
                'reverse_points' => $reversePoints,
            ]);

            return $contribution->fresh(['user', 'wishi', 'cycle']);
        });
    }
}
