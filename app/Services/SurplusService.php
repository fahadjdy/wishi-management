<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SurplusService
{
    public function __construct(protected AuditService $audit) {}

    public function handle(Cycle $cycle, string $action, User $actor, ?int $recipientId = null, ?string $reason = null): Cycle
    {
        return DB::transaction(function () use ($cycle, $action, $actor, $recipientId, $reason) {
            $cycle = Cycle::whereKey($cycle->id)->lockForUpdate()->first();

            // Tender surplus is always deferred to the winner per platform rule.
            if ($cycle->mode === 'tender') {
                throw new \DomainException('Tender surplus is automatically deferred to the winner and paid when the WISHI completes.');
            }

            $surplus = (float) $cycle->surplus;
            if ($surplus <= 0) {
                throw new \DomainException('No surplus to distribute.');
            }
            if (! in_array($action, ['distribute', 'reserve', 'admin_adjust', 'bonus'], true)) {
                throw new \DomainException('Invalid surplus action.');
            }

            $metadata = [
                'cycle_id' => $cycle->id,
                'cycle_number' => $cycle->cycle_number,
                'surplus' => $surplus,
                'action' => $action,
                'reason' => $reason,
            ];

            if ($action === 'bonus') {
                if (! $recipientId) {
                    throw new \DomainException('Recipient is required for bonus surplus action.');
                }
                $valid = $cycle->wishi->members()->where('user_id', $recipientId)->whereIn('status', ['approved', 'active'])->exists();
                if (! $valid) {
                    throw new \DomainException('Recipient must be an active member of the WISHI.');
                }
                $cycle->update([
                    'surplus_action' => $action,
                    'surplus_recipient_id' => $recipientId,
                ]);
                $metadata['recipient_id'] = $recipientId;
            } elseif ($action === 'distribute') {
                $members = $cycle->wishi->members()
                    ->whereIn('status', ['approved', 'active'])
                    ->get();
                $perMember = round($surplus / max(1, $members->count()), 2);
                $cycle->update([
                    'surplus_action' => $action,
                    'surplus_recipient_id' => null,
                ]);
                $metadata['per_member_share'] = $perMember;
                $metadata['member_count'] = $members->count();
            } else {
                $cycle->update([
                    'surplus_action' => $action,
                    'surplus_recipient_id' => null,
                ]);
            }

            $this->audit->log($cycle->wishi, $actor, 'surplus_handled', "Surplus action: {$action} (₹{$surplus})", $metadata);

            return $cycle->fresh();
        });
    }
}
