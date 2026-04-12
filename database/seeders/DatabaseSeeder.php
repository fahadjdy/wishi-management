<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\CreditScoreLog;
use App\Models\Cycle;
use App\Models\Payout;
use App\Models\Tender;
use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Rich seed covering every operational state a WISHI can be in:
 *   - completed (all cycles done)            — WISHI #1
 *   - active mid-life, some paid / some not  — WISHI #2, #8, #10
 *   - active, all paid (selection pending)   — WISHI #3
 *   - active tender with deferred payouts    — WISHI #9
 *   - draft, under-filled                    — WISHI #4, #6
 *   - draft, full, ready to start            — WISHI #5
 *   - cancelled                              — WISHI #7
 */
class DatabaseSeeder extends Seeder
{
    protected $users = [];
    protected $pool = [];

    public function run(): void
    {
        $this->seedFixedAccounts();
        $this->seedFactoryUsers(30);

        $demo = $this->users['demo@wishi.test'];
        $admin2 = $this->users['admin2@wishi.test'];

        $this->wishiCompleted($demo,
            name: 'Mumbai Monthly Pool',
            totalMembers: 6,
            monthlyAmount: 5000,
            durationMonths: 6,
        );

        $this->wishiActiveTenderMidWay($demo,
            name: 'Pune Tech Hybrid Pool',
            totalMembers: 8,
            monthlyAmount: 10000,
            hybridPattern: ['tender', 'random', 'tender'],
        );

        $this->wishiActiveAllPaid($demo,
            name: 'Office Quick Saver',
            totalMembers: 6,
            monthlyAmount: 2000,
            completedCycles: 1,
        );

        $this->wishiDraftPartial($demo,
            name: 'Family Savers',
            totalMembers: 6,
            monthlyAmount: 3000,
            joinedCount: 5,
        );

        $this->wishiDraftFull($admin2,
            name: 'Colony Committee',
            totalMembers: 5,
            monthlyAmount: 4000,
        );

        $this->wishiDraftUnderfilled($demo,
            name: 'Startup Founders Pool',
            totalMembers: 10,
            monthlyAmount: 15000,
            joinedCount: 3,
        );

        $this->wishiCancelled($admin2,
            name: 'Abandoned Experiment',
            totalMembers: 8,
            monthlyAmount: 1000,
        );

        $this->wishiActiveMixedPayments($admin2,
            name: 'Old School Reunion',
            totalMembers: 8,
            monthlyAmount: 2500,
        );

        $this->wishiActiveTenderWithDeferred($demo,
            name: 'Shop Owners Tender',
            totalMembers: 10,
            monthlyAmount: 5000,
        );

        $this->wishiActiveMidWay($admin2,
            name: 'School Alumni Hybrid',
            totalMembers: 8,
            monthlyAmount: 3500,
            hybridPattern: ['random', 'tender', 'random', 'tender'],
            completedCycles: 3,
        );

        $this->command->info(str_repeat('=', 60));
        $this->command->info(' Seeded 10 WISHI scenarios + 7 fixed accounts + 30 factory users');
        $this->command->info(str_repeat('-', 60));
        $this->command->info(' Platform admins:');
        $this->command->info('   demo@wishi.test        / Demo@1234');
        $this->command->info('   admin2@wishi.test      / Admin2@1234');
        $this->command->info(' Members (fixed accounts):');
        foreach (range(1, 5) as $i) {
            $this->command->info("   member{$i}@wishi.test     / Member@1234");
        }
        $this->command->info(' Any factory user            / Password@123');
        $this->command->info(str_repeat('=', 60));
    }

    protected function seedFixedAccounts(): void
    {
        $this->users['demo@wishi.test'] = User::create([
            'name' => 'Fahad Jadiya (Demo Admin)',
            'email' => 'demo@wishi.test',
            'phone' => '+919876543210',
            'password' => Hash::make('Demo@1234'),
            'credit_score' => 95,
            'trust_level' => 'excellent',
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->users['admin2@wishi.test'] = User::create([
            'name' => 'Radhika Singh (Admin)',
            'email' => 'admin2@wishi.test',
            'phone' => '+919000099999',
            'password' => Hash::make('Admin2@1234'),
            'credit_score' => 88,
            'trust_level' => 'good',
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        foreach (range(1, 5) as $i) {
            $this->users["member{$i}@wishi.test"] = User::create([
                'name' => "Member {$i} Test",
                'email' => "member{$i}@wishi.test",
                'phone' => '+91987650' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'password' => Hash::make('Member@1234'),
                'credit_score' => 70 + ($i * 4),
                'trust_level' => $i >= 4 ? 'excellent' : 'good',
                'is_admin' => false,
                'email_verified_at' => now(),
            ]);
        }
    }

    protected function seedFactoryUsers(int $count): void
    {
        $extras = User::factory($count)->create();
        foreach ($extras as $u) {
            $this->users[$u->email] = $u;
        }
    }

    /**
     * Pick `$count` unique members for a WISHI, EXCLUDING the creator and other platform admins.
     * Admins never participate as members — they only manage.
     */
    protected function pickMembers(User $creator, int $count): array
    {
        $pool = array_values(array_filter(
            $this->users,
            fn ($u) => $u->id !== $creator->id && ! $u->is_admin
        ));
        shuffle($pool);
        return array_slice($pool, 0, $count);
    }

    protected function attachMembers(Wishi $wishi, array $members, string $status = 'active'): void
    {
        foreach ($members as $user) {
            WishiMember::create([
                'wishi_id' => $wishi->id,
                'user_id' => $user->id,
                'status' => $status,
                'is_admin' => false, // admins are never stored as members
                'joined_at' => now()->subDays(rand(5, 180)),
            ]);
        }
    }

    /* ───────────────────────────── scenarios ───────────────────────────── */

    /** All cycles completed, winners paid, WISHI marked completed. */
    protected function wishiCompleted(User $creator, string $name, int $totalMembers, float $monthlyAmount, int $durationMonths): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $durationMonths,
            'start_date' => now()->subMonths($durationMonths + 1)->startOfMonth(),
            'current_cycle' => $durationMonths,
            'status' => 'completed',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
            'min_credit_score' => 50,
        ]);

        $members = collect($this->pickMembers($creator, $totalMembers));
        $this->attachMembers($wishi, $members->all());

        for ($n = 1; $n <= $durationMonths; $n++) {
            $winner = $members[$n - 1];
            $this->buildCompletedCycle($wishi, $members, $n, 'random', $winner);
        }

        AuditLog::create([
            'wishi_id' => $wishi->id,
            'user_id' => $creator->id,
            'action' => 'wishi_completed',
            'description' => "All {$durationMonths} cycles completed",
        ]);
        return $wishi;
    }

    /** Draft WISHI with some but not all seats filled. */
    protected function wishiDraftPartial(User $creator, string $name, int $totalMembers, float $monthlyAmount, int $joinedCount): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->addDays(7),
            'current_cycle' => 0,
            'status' => 'draft',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
            'require_approval' => true,
        ]);

        $members = $this->pickMembers($creator, $joinedCount);
        $this->attachMembers($wishi, $members);
        // Add 1 pending join request to showcase approval flow
        $pending = collect($this->users)->firstWhere(fn ($u) =>
            $u->id !== $creator->id
            && ! WishiMember::where('wishi_id', $wishi->id)->where('user_id', $u->id)->exists()
        );
        if ($pending) {
            WishiMember::create([
                'wishi_id' => $wishi->id,
                'user_id' => $pending->id,
                'status' => 'pending',
            ]);
        }
        return $wishi;
    }

    /** Draft WISHI with every seat filled — ready for admin to start. */
    protected function wishiDraftFull(User $creator, string $name, int $totalMembers, float $monthlyAmount): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->addDays(3),
            'current_cycle' => 0,
            'status' => 'draft',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
        ]);
        $members = $this->pickMembers($creator, $totalMembers);
        $this->attachMembers($wishi, $members);
        return $wishi;
    }

    /** Draft WISHI with barely any members — visible waiting banner. */
    protected function wishiDraftUnderfilled(User $creator, string $name, int $totalMembers, float $monthlyAmount, int $joinedCount): Wishi
    {
        return $this->wishiDraftPartial($creator, $name, $totalMembers, $monthlyAmount, $joinedCount);
    }

    /** Cancelled WISHI — never started. */
    protected function wishiCancelled(User $creator, string $name, int $totalMembers, float $monthlyAmount): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->subMonths(2),
            'current_cycle' => 0,
            'status' => 'cancelled',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
        ]);
        $this->attachMembers($wishi, $this->pickMembers($creator, (int) ($totalMembers * 0.4)));
        AuditLog::create([
            'wishi_id' => $wishi->id,
            'user_id' => $creator->id,
            'action' => 'wishi_cancelled',
            'description' => 'WISHI was cancelled before starting (not enough members)',
        ]);
        return $wishi;
    }

    /** Active WISHI mid-cycle, ALL contributions paid in the active cycle. */
    protected function wishiActiveAllPaid(User $creator, string $name, int $totalMembers, float $monthlyAmount, int $completedCycles): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->subMonths($completedCycles + 1)->startOfMonth(),
            'current_cycle' => $completedCycles + 1,
            'status' => 'active',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
        ]);

        $members = collect($this->pickMembers($creator, $totalMembers));
        $this->attachMembers($wishi, $members->all());

        for ($n = 1; $n <= $completedCycles; $n++) {
            $winner = $members[$n - 1];
            $this->buildCompletedCycle($wishi, $members, $n, 'random', $winner);
        }
        $this->buildActiveCycle($wishi, $members, $completedCycles + 1, 'random', allPaid: true, status: 'selection_pending');
        return $wishi;
    }

    /** Active WISHI mid-cycle, HALF the current cycle's members have paid. */
    protected function wishiActiveMidWay(User $creator, string $name, int $totalMembers, float $monthlyAmount, array $hybridPattern, int $completedCycles): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->subMonths($completedCycles + 1)->startOfMonth(),
            'current_cycle' => $completedCycles + 1,
            'status' => 'active',
            'cycle_type' => 'hybrid',
            'hybrid_pattern' => $hybridPattern,
            'winner_selection_mode' => 'auto',
            'tender_start_time' => '10:00',
            'tender_end_time' => '20:00',
        ]);

        $members = collect($this->pickMembers($creator, $totalMembers));
        $this->attachMembers($wishi, $members->all());

        for ($n = 1; $n <= $completedCycles; $n++) {
            $mode = $hybridPattern[($n - 1) % count($hybridPattern)];
            $winner = $members[$n - 1] ?? $members->random();
            $this->buildCompletedCycle($wishi, $members, $n, $mode, $winner);
        }

        $currentMode = $hybridPattern[$completedCycles % count($hybridPattern)];
        $this->buildActiveCycle($wishi, $members, $completedCycles + 1, $currentMode, halfPaid: true);
        return $wishi;
    }

    /** Active WISHI mid-way with a LIVE tender cycle (bidding open) — previous cycles have deferred amounts. */
    protected function wishiActiveTenderMidWay(User $creator, string $name, int $totalMembers, float $monthlyAmount, array $hybridPattern): Wishi
    {
        $completed = 2;
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->subMonths($completed + 1)->startOfMonth(),
            'current_cycle' => $completed + 1,
            'status' => 'active',
            'cycle_type' => 'hybrid',
            'hybrid_pattern' => $hybridPattern,
            'winner_selection_mode' => 'auto',
            'tender_start_time' => '10:00',
            'tender_end_time' => '20:00',
        ]);

        $members = collect($this->pickMembers($creator, $totalMembers));
        $this->attachMembers($wishi, $members->all());

        for ($n = 1; $n <= $completed; $n++) {
            $mode = $hybridPattern[($n - 1) % count($hybridPattern)];
            $winner = $members[$n - 1] ?? $members->random();
            $this->buildCompletedCycle($wishi, $members, $n, $mode, $winner);
        }

        // Cycle #3 is a tender with bidding currently OPEN
        $this->buildLiveTenderCycle($wishi, $members, $completed + 1);
        return $wishi;
    }

    /** Active tender-only WISHI with several completed tender cycles carrying deferred payouts. */
    protected function wishiActiveTenderWithDeferred(User $creator, string $name, int $totalMembers, float $monthlyAmount): Wishi
    {
        $completed = 3;
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->subMonths($completed + 1)->startOfMonth(),
            'current_cycle' => $completed + 1,
            'status' => 'active',
            'cycle_type' => 'tender',
            'winner_selection_mode' => 'auto',
            'tender_start_time' => '10:00',
            'tender_end_time' => '20:00',
        ]);

        $members = collect($this->pickMembers($creator, $totalMembers));
        $this->attachMembers($wishi, $members->all());

        for ($n = 1; $n <= $completed; $n++) {
            $winner = $members[$n - 1];
            $this->buildCompletedCycle($wishi, $members, $n, 'tender', $winner);
        }

        // Cycle #4 contribution_open
        $this->buildActiveCycle($wishi, $members, $completed + 1, 'tender', halfPaid: false, status: 'contribution_open', allPaid: false);
        return $wishi;
    }

    /** Active WISHI with a MIX of paid / late / pending contributions for realism. */
    protected function wishiActiveMixedPayments(User $creator, string $name, int $totalMembers, float $monthlyAmount): Wishi
    {
        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'created_by' => $creator->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyAmount,
            'duration_months' => $totalMembers,
            'start_date' => now()->subMonths(2)->startOfMonth(),
            'current_cycle' => 3,
            'status' => 'active',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
        ]);

        $members = collect($this->pickMembers($creator, $totalMembers));
        $this->attachMembers($wishi, $members->all());

        for ($n = 1; $n <= 2; $n++) {
            $winner = $members[$n - 1];
            $this->buildCompletedCycle($wishi, $members, $n, 'random', $winner);
        }

        // Cycle #3 active — mixed states
        $dueDate = now()->subDays(4)->toDateString(); // past due → some already late
        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => 3,
            'mode' => 'random',
            'status' => 'contribution_open',
            'total_pool' => $wishi->totalPool(),
            'contribution_due_at' => $dueDate,
        ]);

        foreach ($members as $idx => $member) {
            // 3 paid on-time, 3 late (unpaid overdue), 2 pending not-yet-due-in-data-but-late-by-date
            if ($idx < 3) {
                $status = 'paid';
                $paidAt = now()->subDays(6);
            } elseif ($idx < 6) {
                $status = 'late';
                $paidAt = null;
            } else {
                $status = 'pending';
                $paidAt = null;
            }
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $member->id,
                'amount' => $wishi->monthly_contribution,
                'status' => $status,
                'due_date' => $dueDate,
                'paid_at' => $paidAt,
                'payment_method' => $status === 'paid' ? 'upi' : null,
            ]);
        }
        return $wishi;
    }

    /* ───────────────────────────── primitives ───────────────────────────── */

    protected function buildCompletedCycle(Wishi $wishi, $members, int $n, string $mode, User $winner): Cycle
    {
        // Cycle #1 is always the organizer payout — admin wins, mode is random, no bidding.
        $isOrganizerCycle = ($n === 1);
        if ($isOrganizerCycle) {
            $mode = 'random';
            $winner = $wishi->creator;
        }

        $totalPool = $wishi->totalPool();
        $winningBid = $mode === 'tender' ? $totalPool - rand(2000, 8000) : null;
        $surplus = $mode === 'tender' ? max(0, $totalPool - $winningBid) : 0;
        $start = $wishi->start_date->copy()->addMonths($n - 1);
        $dueDate = $start->copy()->addDays(7);
        $paidOutAt = $dueDate->copy()->addDays(2);

        $wishiFinished = $wishi->status === 'completed';
        $deferredReleasedAt = ($mode === 'tender' && $surplus > 0 && $wishiFinished) ? $paidOutAt->copy()->addDay() : null;

        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $n,
            'mode' => $mode,
            'status' => 'completed',
            'total_pool' => $totalPool,
            'winner_id' => $winner->id,
            'winning_bid' => $winningBid,
            'surplus' => $surplus,
            'surplus_action' => $surplus > 0
                ? ($mode === 'tender' ? 'deferred_to_winner' : 'distribute')
                : null,
            'deferred_amount' => $mode === 'tender' ? $surplus : 0,
            'deferred_released_at' => $deferredReleasedAt,
            'selection_method' => $isOrganizerCycle
                ? 'organizer_payout'
                : ($mode === 'tender' ? 'auto_tender' : 'auto_random'),
            'selection_seed' => ($mode === 'random' && ! $isOrganizerCycle) ? bin2hex(random_bytes(32)) : null,
            'selected_at' => $dueDate->copy()->addDay(),
            'payout_amount' => $mode === 'tender' ? $winningBid : $totalPool,
            'paid_out_at' => $paidOutAt,
            'contribution_due_at' => $dueDate,
        ]);

        foreach ($members as $m) {
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $m->id,
                'amount' => $wishi->monthly_contribution,
                'status' => 'paid',
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $dueDate->copy()->subDay(),
                'payment_method' => 'upi',
            ]);
            CreditScoreLog::create([
                'user_id' => $m->id,
                'wishi_id' => $wishi->id,
                'cycle_id' => $cycle->id,
                'action' => 'on_time_payment',
                'points' => 10,
                'score_before' => max(0, $m->credit_score - 10),
                'score_after' => $m->credit_score,
            ]);
        }

        if ($mode === 'tender') {
            foreach ($members->take(4) as $idx => $bidder) {
                Tender::create([
                    'cycle_id' => $cycle->id,
                    'wishi_id' => $wishi->id,
                    'user_id' => $bidder->id,
                    'bid_amount' => $bidder->id === $winner->id ? $winningBid : $winningBid + rand(500, 3000),
                    'is_winning_bid' => $bidder->id === $winner->id,
                    'placed_at' => $dueDate->copy()->subDays(2),
                ]);
            }
        }

        Payout::create([
            'cycle_id' => $cycle->id,
            'wishi_id' => $wishi->id,
            'user_id' => $winner->id,
            'amount' => $cycle->payout_amount,
            'method' => 'bank_transfer',
            'reference' => 'TXN' . strtoupper(Str::random(10)),
            'paid_at' => $paidOutAt,
        ]);

        // Release deferred payout for finished wishi
        if ($deferredReleasedAt) {
            $deferredPayout = Payout::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $winner->id,
                'amount' => $surplus,
                'method' => 'bank_transfer',
                'reference' => 'DEFERRED-' . strtoupper(Str::random(6)),
                'notes' => "Deferred tender surplus released on WISHI completion.",
                'paid_at' => $deferredReleasedAt,
            ]);
            $cycle->update(['deferred_payout_id' => $deferredPayout->id]);
        }

        // Only flag member-winner; organizer (admin) doesn't have a member row.
        if (! $isOrganizerCycle) {
            WishiMember::where('wishi_id', $wishi->id)
                ->where('user_id', $winner->id)
                ->update(['has_won' => true, 'won_in_cycle' => $n]);
        }

        AuditLog::create([
            'wishi_id' => $wishi->id,
            'user_id' => $wishi->created_by,
            'action' => $isOrganizerCycle ? 'organizer_payout' : 'winner_selected',
            'description' => $isOrganizerCycle
                ? "Cycle #1 · Organizer payout to admin {$winner->name}"
                : "Cycle #{$n} ({$mode}) · winner " . $winner->name,
            'metadata' => ['cycle_id' => $cycle->id, 'winner_id' => $winner->id, 'organizer_cycle' => $isOrganizerCycle],
        ]);
        return $cycle;
    }

    protected function buildActiveCycle(Wishi $wishi, $members, int $n, string $mode, bool $halfPaid = false, bool $allPaid = false, string $status = 'contribution_open'): Cycle
    {
        $dueDate = $wishi->start_date->copy()->addMonths($n - 1)->addDays(7);
        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $n,
            'mode' => $mode,
            'status' => $status,
            'total_pool' => $wishi->totalPool(),
            'contribution_due_at' => $dueDate,
            'tender_opens_at' => $mode === 'tender' ? now()->subHours(2) : null,
            'tender_closes_at' => $mode === 'tender' ? now()->addDays(2) : null,
        ]);

        foreach ($members as $idx => $m) {
            if ($allPaid) {
                $contStatus = 'paid';
                $paidAt = now()->subDays(rand(1, 3));
            } elseif ($halfPaid) {
                $paid = $idx < (int) ceil(count($members) / 2);
                $contStatus = $paid ? 'paid' : 'pending';
                $paidAt = $paid ? now()->subDays(2) : null;
            } else {
                $contStatus = 'pending';
                $paidAt = null;
            }
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $m->id,
                'amount' => $wishi->monthly_contribution,
                'status' => $contStatus,
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $paidAt,
                'payment_method' => $contStatus === 'paid' ? 'upi' : null,
            ]);
        }
        return $cycle;
    }

    protected function buildLiveTenderCycle(Wishi $wishi, $members, int $n): Cycle
    {
        $dueDate = $wishi->start_date->copy()->addMonths($n - 1)->addDays(7);
        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $n,
            'mode' => 'tender',
            'status' => 'bidding_open',
            'total_pool' => $wishi->totalPool(),
            'contribution_due_at' => $dueDate,
            'tender_opens_at' => now()->subHours(2),
            'tender_closes_at' => now()->addDays(2),
        ]);

        foreach ($members as $m) {
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $m->id,
                'amount' => $wishi->monthly_contribution,
                'status' => 'paid',
                'due_date' => $dueDate->toDateString(),
                'paid_at' => now()->subDays(1),
                'payment_method' => 'upi',
            ]);
        }

        // Place bids from members who haven't won yet
        $eligible = $members->filter(fn ($m) => ! WishiMember::where('wishi_id', $wishi->id)->where('user_id', $m->id)->value('has_won'))->take(3);
        foreach ($eligible as $idx => $bidder) {
            Tender::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $bidder->id,
                'bid_amount' => $wishi->totalPool() - 1000 * ($idx + 1),
                'placed_at' => now()->subHour(),
            ]);
        }

        return $cycle;
    }
}
