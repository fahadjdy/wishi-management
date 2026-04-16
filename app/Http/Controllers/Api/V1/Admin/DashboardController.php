<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\Payout;
use App\Models\Tender;
use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = now();

        return response()->json([
            'overview' => [
                'total_users' => User::withTrashed()->count(),
                'active_users' => User::whereNull('deleted_at')->where(function ($q) {
                    $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                })->count(),
                'total_admins' => User::where('is_admin', true)->count(),
                'locked_users' => User::whereNotNull('locked_until')->where('locked_until', '>', $now)->count(),
                'total_wishis' => Wishi::count(),
                'active_wishis' => Wishi::where('status', 'active')->count(),
                'completed_wishis' => Wishi::where('status', 'completed')->count(),
                'total_pool_value' => (float) Wishi::where('status', 'active')->sum(DB::raw('monthly_contribution * total_members')),
                'total_contributions_paid' => (float) Contribution::whereIn('status', ['paid', 'late'])->sum('amount'),
                'total_payouts' => (float) Payout::sum('amount'),
                'total_cycles' => Cycle::count(),
                'completed_cycles' => Cycle::where('status', 'completed')->count(),
                'open_tenders' => Cycle::where('status', 'bidding_open')->where('mode', 'tender')->count(),
                'missed_contributions' => Contribution::where('status', 'missed')->count(),
            ],

            'users_by_role' => [
                ['role' => 'Platform Admins', 'count' => User::where('is_admin', true)->count()],
                ['role' => 'Members', 'count' => User::where('is_admin', false)->count()],
            ],

            'users_by_trust' => User::select('trust_level', DB::raw('COUNT(*) as count'))
                ->groupBy('trust_level')
                ->get()
                ->map(fn ($r) => ['trust_level' => ucfirst($r->trust_level), 'count' => (int) $r->count])
                ->values(),

            'wishis_by_status' => Wishi::select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->map(fn ($r) => ['status' => ucfirst($r->status), 'count' => (int) $r->count])
                ->values(),

            'wishis_by_type' => Wishi::select('cycle_type', DB::raw('COUNT(*) as count'))
                ->groupBy('cycle_type')
                ->get()
                ->map(fn ($r) => ['cycle_type' => ucfirst($r->cycle_type), 'count' => (int) $r->count])
                ->values(),

            'signups_last_30_days' => $this->dailySeries(User::query(), 'created_at', 30),
            'contributions_last_30_days' => $this->dailyAmountSeries(Contribution::query()->whereIn('status', ['paid', 'late']), 'paid_at', 'amount', 30),
            'payouts_last_30_days' => $this->dailyAmountSeries(Payout::query(), 'paid_at', 'amount', 30),

            'top_contributors' => Contribution::select('user_id', DB::raw('SUM(amount) as total'))
                ->whereIn('status', ['paid', 'late'])
                ->groupBy('user_id')
                ->orderByDesc('total')
                ->limit(5)
                ->with('user:id,name')
                ->get()
                ->map(fn ($c) => [
                    'user_id' => $c->user_id,
                    'name' => $c->user?->name,
                    'total' => (float) $c->total,
                ]),

            // Member-initiated join requests awaiting admin approval. Admin-initiated
            // invitations (invited_by_admin=true) are member-side work and live on the
            // member dashboard, so they're excluded here.
            'pending_join_requests' => WishiMember::where('status', 'pending')
                ->where('invited_by_admin', false)
                ->whereHas('wishi', fn ($q) => $q->whereIn('status', ['planned', 'active']))
                ->with(['user:id,name,email,avatar_path,credit_score,trust_level', 'wishi:id,uuid,name,status,monthly_contribution,total_members,duration_months,cycle_type'])
                ->orderBy('created_at')
                ->limit(25)
                ->get()
                ->map(fn ($m) => [
                    'member_id' => $m->id,
                    'user' => [
                        'id' => $m->user?->id,
                        'name' => $m->user?->name,
                        'email' => $m->user?->email,
                        'avatar_url' => $m->user?->avatar_url,
                        'credit_score' => (int) ($m->user?->credit_score ?? 0),
                        'trust_level' => $m->user?->trust_level,
                    ],
                    'wishi' => [
                        'uuid' => $m->wishi?->uuid,
                        'name' => $m->wishi?->name,
                        'status' => $m->wishi?->status,
                        'monthly_contribution' => (float) ($m->wishi?->monthly_contribution ?? 0),
                        'duration_months' => (int) ($m->wishi?->duration_months ?? 0),
                        'total_members' => (int) ($m->wishi?->total_members ?? 0),
                        'cycle_type' => $m->wishi?->cycle_type,
                    ],
                    'requested_at' => optional($m->created_at)?->toIso8601String(),
                ]),

            'recent_audit' => AuditLog::with('user:id,name', 'wishi:id,name')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'description' => $l->description,
                    'user_name' => $l->user?->name,
                    'wishi_name' => $l->wishi?->name,
                    'created_at' => $l->created_at?->toIso8601String(),
                ]),
        ]);
    }

    protected function dailySeries($query, string $column, int $days): array
    {
        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $rows = (clone $query)->select(DB::raw("DATE({$column}) as d"), DB::raw('COUNT(*) as c'))
            ->whereNotNull($column)
            ->whereBetween($column, [$start, $end->copy()->endOfDay()])
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('c', 'd')
            ->toArray();

        $series = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $series[] = ['date' => $key, 'count' => (int) ($rows[$key] ?? 0)];
        }
        return $series;
    }

    protected function dailyAmountSeries($query, string $column, string $amountCol, int $days): array
    {
        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $rows = (clone $query)->select(DB::raw("DATE({$column}) as d"), DB::raw("SUM({$amountCol}) as s"))
            ->whereNotNull($column)
            ->whereBetween($column, [$start, $end->copy()->endOfDay()])
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('s', 'd')
            ->toArray();

        $series = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $series[] = ['date' => $key, 'amount' => (float) ($rows[$key] ?? 0)];
        }
        return $series;
    }
}
