<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\CreditScoreLog;
use App\Models\Cycle;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /** @var array<string, User> */
    protected array $users = [];

    public function run(): void
    {
        $admin = $this->createAdmin();
        $members = $this->createMembers();

        $this->seedKoloniMember($admin, $members);

        $this->command->info(str_repeat('=', 60));
        $this->command->info(' Seeded "Koloni Member" WISHI');
        $this->command->info(str_repeat('-', 60));
        $this->command->info(' Admin:   admin@example.com / password');
        foreach ($members as $m) {
            $this->command->info(sprintf('   %-32s / password', $m->email));
        }
        $this->command->info(str_repeat('=', 60));
    }

    protected function createAdmin(): User
    {
        $admin = User::create([
            'name' => 'Koloni Admin',
            'email' => 'admin@example.com',
            'phone' => '+919000000000',
            'password' => Hash::make('password'),
            'credit_score' => 95,
            'trust_level' => 'excellent',
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $this->users[$admin->email] = $admin;
        return $admin;
    }

    /** @return array<string, User> keyed by email */
    protected function createMembers(): array
    {
        $names = [
            'Salman Jadiya',
            'Ayan Jadiya',
            'Salman Sangla',
            'Nijam Palasara',
            'Farman Palasara',
            'Yasmin Bhabhi',
            'Munera Bhabhi',
            'Rubina Bhabhi',
            'Afjal Badhara',
            'Mustak Badhara',
        ];

        $members = [];
        foreach ($names as $i => $name) {
            [$first, $last] = explode(' ', $name, 2);
            $email = strtolower($first . '.' . $last) . '@example.com';
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => '+9198760' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'),
                'credit_score' => 80,
                'trust_level' => 'good',
                'is_admin' => false,
                'email_verified_at' => now(),
            ]);
            $this->users[$email] = $user;
            $members[$email] = $user;
        }
        return $members;
    }

    /**
     * @param array<string, User> $members
     */
    protected function seedKoloniMember(User $admin, array $members): void
    {
        $totalMembers = count($members);
        $monthlyContribution = 5000;
        // Anchor cycle #3 to "today" so the demo wishi has a live, current cycle:
        //   cycle 1 = today − 2 months  (completed, organizer payout)
        //   cycle 2 = today − 1 month   (completed, Rubina wins)
        //   cycle 3 = today             (contribution_open, 5 pending / 5 paid)
        $startDate = now()->subMonthsNoOverflow(2)->startOfDay();

        $wishi = Wishi::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Koloni Member',
            'created_by' => $admin->id,
            'total_members' => $totalMembers,
            'monthly_contribution' => $monthlyContribution,
            'duration_months' => $totalMembers,
            'cycle_frequency' => 'monthly',
            'start_date' => $startDate,
            'wishi_opening_time' => '00:00:00',
            'current_cycle' => 3,
            'status' => 'active',
            'cycle_type' => 'random',
            'winner_selection_mode' => 'auto',
            'require_approval' => false,
        ]);

        // Token #1 is reserved for admin/organizer (FLOW.md §4). Real
        // members start at #2.
        $token = 2;
        foreach ($members as $user) {
            WishiMember::create([
                'wishi_id' => $wishi->id,
                'user_id' => $user->id,
                'status' => 'active',
                'is_admin' => false,
                'invited_by_admin' => true,
                'joined_at' => $startDate->copy()->subDays(7),
                'token_no' => $token++,
            ]);
        }

        $memberList = array_values($members);

        $this->buildCompletedCycle($wishi, $memberList, 1, $admin, isOrganizerCycle: true);

        $rubina = $members['rubina.bhabhi@example.com'];
        $this->buildCompletedCycle($wishi, $memberList, 2, $rubina, isOrganizerCycle: false);

        $pendingEmails = [
            'yasmin.bhabhi@example.com',
            'munera.bhabhi@example.com',
            'rubina.bhabhi@example.com',
            'afjal.badhara@example.com',
            'mustak.badhara@example.com',
        ];
        $this->buildActiveCycleWithPending($wishi, $memberList, 3, $pendingEmails);
    }

    /**
     * @param array<int, User> $members
     */
    protected function buildCompletedCycle(Wishi $wishi, array $members, int $n, User $winner, bool $isOrganizerCycle): Cycle
    {
        $totalPool = $wishi->totalPool();
        $start = $wishi->start_date->copy()->addMonthsNoOverflow($n - 1);
        $dueDate = $start->copy();
        $paidOutAt = $dueDate->copy()->addDays(2);

        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $n,
            'mode' => 'random',
            'status' => 'completed',
            'total_pool' => $totalPool,
            'winner_id' => $winner->id,
            'surplus' => 0,
            'deferred_amount' => 0,
            'selection_method' => $isOrganizerCycle ? 'organizer_payout' : 'auto_random',
            'selection_seed' => $isOrganizerCycle ? null : bin2hex(random_bytes(32)),
            'selected_at' => $dueDate->copy()->addDay(),
            'payout_amount' => $totalPool,
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

        Payout::create([
            'cycle_id' => $cycle->id,
            'wishi_id' => $wishi->id,
            'user_id' => $winner->id,
            'amount' => $cycle->payout_amount,
            'method' => 'bank_transfer',
            'reference' => 'TXN' . strtoupper(Str::random(10)),
            'paid_at' => $paidOutAt,
        ]);

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
                : "Cycle #{$n} · winner {$winner->name}",
            'metadata' => ['cycle_id' => $cycle->id, 'winner_id' => $winner->id],
        ]);

        return $cycle;
    }

    /**
     * @param array<int, User> $members
     * @param array<int, string> $pendingEmails
     */
    protected function buildActiveCycleWithPending(Wishi $wishi, array $members, int $n, array $pendingEmails): Cycle
    {
        $dueDate = $wishi->start_date->copy()->addMonthsNoOverflow($n - 1);
        $cycle = Cycle::create([
            'wishi_id' => $wishi->id,
            'cycle_number' => $n,
            'mode' => 'random',
            'status' => 'contribution_open',
            'total_pool' => $wishi->totalPool(),
            'contribution_due_at' => $dueDate,
        ]);

        $pendingSet = array_flip($pendingEmails);

        foreach ($members as $m) {
            $isPending = isset($pendingSet[$m->email]);
            Contribution::create([
                'cycle_id' => $cycle->id,
                'wishi_id' => $wishi->id,
                'user_id' => $m->id,
                'amount' => $wishi->monthly_contribution,
                'status' => $isPending ? 'pending' : 'paid',
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $isPending ? null : now()->subDays(2),
                'payment_method' => $isPending ? null : 'upi',
            ]);
        }

        return $cycle;
    }
}
