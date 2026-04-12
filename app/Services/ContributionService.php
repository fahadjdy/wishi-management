<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\User;
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

            return $contribution->fresh();
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
}
