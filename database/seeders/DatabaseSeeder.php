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

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $demo = User::factory()->create([
            'name' => 'Fahad Jadiya',
            'email' => 'demo@wishi.test',
            'password' => Hash::make('Demo@1234'),
            'phone' => '+919876543210',
            'credit_score' => 95,
            'trust_level' => 'excellent',
            'is_admin' => true,
        ]);

        $users = User::factory(9)->create();
        $allUsers = collect([$demo])->merge($users);

        // WISHI 1 — random-mode, 10 members
        $w1 = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Mumbai Friends Monthly Pool',
            'created_by' => $demo->id,
            'total_members' => 12,
            'monthly_contribution' => 5000,
            'duration_months' => 12,
            'start_date' => now()->subMonths(4)->startOfMonth(),
            'current_cycle' => 0,
            'status' => 'active',
            'auto_join' => false,
            'require_approval' => true,
            'winner_selection_mode' => 'auto',
            'cycle_type' => 'random',
            'min_credit_score' => 50,
        ]);

        foreach ($allUsers as $user) {
            WishiMember::create([
                'wishi_id' => $w1->id,
                'user_id' => $user->id,
                'status' => 'active',
                'is_admin' => $user->id === $demo->id,
                'joined_at' => now()->subMonths(5),
            ]);
        }

        $this->seedCompletedCycles($w1, $allUsers, 3, 'random');
        $this->seedActiveCycle($w1, $allUsers, 4, 'random');

        // WISHI 2 — hybrid pattern, 8 members
        $w2 = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pune Tech Crew Hybrid Pool',
            'created_by' => $users[0]->id,
            'total_members' => 8,
            'monthly_contribution' => 10000,
            'duration_months' => 8,
            'start_date' => now()->subMonths(3)->startOfMonth(),
            'current_cycle' => 0,
            'status' => 'active',
            'auto_join' => false,
            'require_approval' => true,
            'winner_selection_mode' => 'auto',
            'cycle_type' => 'hybrid',
            'hybrid_pattern' => ['tender', 'random', 'tender'],
            'min_credit_score' => 60,
            'tender_start_time' => '10:00',
            'tender_end_time' => '20:00',
        ]);

        $w2Members = $allUsers->take(8);
        foreach ($w2Members as $user) {
            WishiMember::create([
                'wishi_id' => $w2->id,
                'user_id' => $user->id,
                'status' => 'active',
                'is_admin' => $user->id === $users[0]->id,
                'joined_at' => now()->subMonths(4),
            ]);
        }

        $this->seedCompletedCycles($w2, $w2Members, 2, 'hybrid', ['tender', 'random', 'tender']);
        $this->seedActiveTenderCycle($w2, $w2Members, 3);

        // Pending join request for demo
        $pendingUser = User::factory()->create(['name' => 'Pending User', 'credit_score' => 75]);
        WishiMember::create([
            'wishi_id' => $w1->id,
            'user_id' => $pendingUser->id,
            'status' => 'pending',
        ]);

        $this->command->info('==== Seeded demo data ====');
        $this->command->info('Login: demo@wishi.test / Demo@1234');
    }

    protected function seedCompletedCycles(Wishi $wishi, $members, int $count, string $type, array $hybridPattern = []): void
    {
        $startDate = $wishi->start_date->copy();
        for ($n = 1; $n <= $count; $n++) {
            $mode = match ($type) {
                'tender' => 'tender',
                'hybrid' => $hybridPattern[($n - 1) % count($hybridPattern)] ?? 'random',
                default => 'random',
            };

            $eligible = $members->filter(fn ($u) => ! WishiMember::where('wishi_id', $wishi->id)->where('user_id', $u->id)->value('has_won'));
            $winner = $eligible->values()->get($n - 1) ?? $eligible->first() ?? $members->first();

            $totalPool = $wishi->totalPool();
            $winningBid = $mode === 'tender' ? $totalPool - rand(1000, 5000) : null;
            $surplus = $mode === 'tender' ? max(0, $totalPool - $winningBid) : 0;

            $dueDate = $startDate->copy()->addMonths($n - 1)->addDays(7);
            $cycle = Cycle::create([
                'wishi_id' => $wishi->id,
                'cycle_number' => $n,
                'mode' => $mode,
                'status' => 'completed',
                'total_pool' => $totalPool,
                'winner_id' => $winner->id,
                'winning_bid' => $winningBid,
                'surplus' => $surplus,
                'surplus_action' => $surplus > 0 ? ($mode === 'tender' ? 'deferred_to_winner' : 'distribute') : null,
                'deferred_amount' => $mode === 'tender' ? $surplus : 0,
                'selection_method' => $mode === 'tender' ? 'auto_tender' : 'auto_random',
                'selection_seed' => $mode === 'random' ? bin2hex(random_bytes(32)) : null,
                'selected_at' => $dueDate->copy()->addDay(),
                'payout_amount' => $mode === 'tender' ? $winningBid : $totalPool,
                'paid_out_at' => $dueDate->copy()->addDays(2),
                'contribution_due_at' => $dueDate,
            ]);

            foreach ($members as $member) {
                Contribution::create([
                    'cycle_id' => $cycle->id,
                    'wishi_id' => $wishi->id,
                    'user_id' => $member->id,
                    'amount' => $wishi->monthly_contribution,
                    'status' => 'paid',
                    'due_date' => $dueDate->toDateString(),
                    'paid_at' => $dueDate->copy()->subDay(),
                    'payment_method' => 'upi',
                ]);

                CreditScoreLog::create([
                    'user_id' => $member->id,
                    'wishi_id' => $wishi->id,
                    'cycle_id' => $cycle->id,
                    'action' => 'on_time_payment',
                    'points' => 10,
                    'score_before' => max(0, $member->credit_score - 10),
                    'score_after' => $member->credit_score,
                ]);
            }

            if ($mode === 'tender') {
                foreach ($members->take(4) as $bidder) {
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
                'paid_at' => $dueDate->copy()->addDays(2),
            ]);

            WishiMember::where('wishi_id', $wishi->id)
                ->where('user_id', $winner->id)
                ->update(['has_won' => true, 'won_in_cycle' => $n]);

            AuditLog::create([
                'wishi_id' => $wishi->id,
                'user_id' => $wishi->created_by,
                'action' => 'winner_selected',
                'description' => "Cycle #{$n} winner selected ({$mode})",
                'metadata' => ['cycle_id' => $cycle->id, 'winner_id' => $winner->id, 'method' => $cycle->selection_method],
            ]);

            $wishi->update(['current_cycle' => $n]);
        }
    }

    protected function seedActiveCycle(Wishi $wishi, $members, int $cycleNumber, string $mode): void
    {
        $dueDate = $wishi->start_date->copy()->addMonths($cycleNumber - 1)->addDays(7);
        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $cycleNumber,
            'mode' => $mode,
            'status' => 'contribution_open',
            'total_pool' => $wishi->totalPool(),
            'contribution_due_at' => $dueDate,
        ]);

        foreach ($members as $idx => $member) {
            $status = $idx < (int) (count($members) * 0.6) ? 'paid' : 'pending';
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $member->id,
                'amount' => $wishi->monthly_contribution,
                'status' => $status,
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $status === 'paid' ? now()->subDays(2) : null,
                'payment_method' => $status === 'paid' ? 'upi' : null,
            ]);
        }

        $wishi->update(['current_cycle' => $cycleNumber]);
    }

    protected function seedActiveTenderCycle(Wishi $wishi, $members, int $cycleNumber): void
    {
        $dueDate = $wishi->start_date->copy()->addMonths($cycleNumber - 1)->addDays(7);
        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $cycleNumber,
            'mode' => 'tender',
            'status' => 'bidding_open',
            'total_pool' => $wishi->totalPool(),
            'contribution_due_at' => $dueDate,
            'tender_opens_at' => now()->subHours(2),
            'tender_closes_at' => now()->addDays(2),
        ]);

        foreach ($members as $member) {
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $member->id,
                'amount' => $wishi->monthly_contribution,
                'status' => 'paid',
                'due_date' => $dueDate->toDateString(),
                'paid_at' => now()->subDays(1),
                'payment_method' => 'upi',
            ]);
        }

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

        $wishi->update(['current_cycle' => $cycleNumber]);
    }
}
