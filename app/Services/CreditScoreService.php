<?php

namespace App\Services;

use App\Models\CreditScoreLog;
use App\Models\Cycle;
use App\Models\User;
use App\Models\Wishi;
use Illuminate\Support\Facades\DB;

class CreditScoreService
{
    public const POINTS = [
        'on_time_payment' => 10,
        'early_payment' => 15,
        'late_payment' => -5,
        'missed_payment' => -20,
    ];

    public function updateScore(User $user, string $action, ?Wishi $wishi = null, ?Cycle $cycle = null, ?int $customPoints = null, ?string $reason = null): CreditScoreLog
    {
        $points = $customPoints ?? (self::POINTS[$action] ?? 0);

        return DB::transaction(function () use ($user, $action, $wishi, $cycle, $points, $reason) {
            $user->refresh();
            $before = (int) $user->credit_score;
            $after = max(0, min(100, $before + $points));

            $user->update([
                'credit_score' => $after,
                'trust_level' => $this->resolveTrustLevel($after),
            ]);

            return CreditScoreLog::create([
                'user_id' => $user->id,
                'wishi_id' => $wishi?->id,
                'cycle_id' => $cycle?->id,
                'action' => $action,
                'points' => $points,
                'score_before' => $before,
                'score_after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    public function resolveTrustLevel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'average',
            default => 'risky',
        };
    }
}
