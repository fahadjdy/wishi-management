<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\Payout;
use App\Models\Wishi;
use App\Models\WishiMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Wishis the user is effectively part of = WishiMember rows for real
        // members PLUS every WISHI they created (admin holds seat #1 as
        // organizer per FLOW.md §4, even though no wishi_members row exists).
        // Dashboard counts/totals must treat both paths as "member of".
        $memberWishiIds = WishiMember::where('user_id', $user->id)
            ->whereIn('status', ['approved', 'active'])
            ->whereHas('wishi', fn ($q) => $q->where('status', 'active'))
            ->pluck('wishi_id');
        $organizerWishiIds = Wishi::where('created_by', $user->id)
            ->where('status', 'active')
            ->pluck('id');
        $activeWishiIds = $memberWishiIds->merge($organizerWishiIds)->unique()->values();

        $totalContributed = Contribution::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'late'])
            ->sum('amount');

        $totalWon = Payout::where('user_id', $user->id)->sum('amount');

        // Canonical "still owed" indicator is paid_at IS NULL (status='late'
        // is ambiguous — it can also mean "paid after due"). Filtering by
        // status alone leaks already-paid contributions onto the dashboard.
        $upcomingPayments = Contribution::where('user_id', $user->id)
            ->whereNull('paid_at')
            ->whereIn('status', ['pending', 'late'])
            ->orderBy('due_date')
            ->with('wishi:id,uuid,name', 'cycle:id,cycle_number')
            ->limit(10)
            ->get();

        $cyclesActive = Cycle::whereIn('wishi_id', $activeWishiIds)
            ->whereIn('status', ['contribution_open', 'bidding_open', 'selection_pending'])
            ->count();

        // Lifetime count of wishis this user organized — drafts, planned, active
        // and completed all count toward "N created by you" on the dashboard.
        // Active-only count is already exposed separately as active_wishis_count.
        $createdCount = Wishi::where('created_by', $user->id)->count();

        // Open-to-join WISHIs the user hasn't joined or requested yet. Surfaced on
        // the dashboard so a newly-published WISHI is immediately discoverable.
        // Only `planned` status is publicly visible — `draft` stays admin-only.
        $joinable = Wishi::where('status', 'planned')
            ->where('created_by', '!=', $user->id)
            ->whereDoesntHave('members', fn ($m) => $m->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'approved', 'active']))
            ->withCount('activeMembers')
            ->with('creator:id,name')
            ->latest()
            ->limit(12)
            ->get()
            ->filter(fn ($w) => (int) $w->active_members_count < $w->memberCapacity())
            ->map(fn ($w) => [
                'uuid' => $w->uuid,
                'name' => $w->name,
                'creator_name' => $w->creator?->name,
                'monthly_contribution' => (float) $w->monthly_contribution,
                'total_members' => (int) $w->total_members,
                'member_capacity' => $w->memberCapacity(),
                'active_members' => (int) $w->active_members_count,
                'seats_left' => max(0, $w->memberCapacity() - (int) $w->active_members_count),
                'duration_months' => (int) $w->duration_months,
                'cycle_type' => $w->cycle_type,
                'start_date' => optional($w->start_date)?->toDateString(),
                'status' => $w->status,
                'require_approval' => (bool) $w->require_approval,
            ])
            ->values();

        // WISHIs this user created that are about to open (within 5 days) or
        // are already past start_date but still in draft — admin needs to act.
        $upcomingOpenings = Wishi::where('created_by', $user->id)
            ->where('status', 'draft')
            ->whereNotNull('start_date')
            ->where('start_date', '<=', now()->addDays(5)->toDateString())
            ->orderBy('start_date')
            ->get(['id', 'uuid', 'name', 'start_date', 'total_members', 'monthly_contribution', 'duration_months'])
            ->map(function ($w) {
                $activeMembers = $w->activeMembers()->count();
                return [
                    'uuid' => $w->uuid,
                    'name' => $w->name,
                    'start_date' => $w->start_date?->toDateString(),
                    'total_members' => (int) $w->total_members,
                    'member_capacity' => $w->memberCapacity(),
                    'active_members' => $activeMembers,
                    'monthly_contribution' => (float) $w->monthly_contribution,
                    'duration_months' => (int) $w->duration_months,
                    'is_full' => $activeMembers >= $w->memberCapacity(),
                ];
            })->values();

        // Pending admin invitations the member needs to accept / decline
        $invitations = WishiMember::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('invited_by_admin', true)
            ->with(['wishi.creator:id,name'])
            ->get()
            ->map(fn ($m) => [
                'wishi_uuid' => $m->wishi?->uuid,
                'wishi_name' => $m->wishi?->name,
                'admin_name' => $m->wishi?->creator?->name,
                'monthly_contribution' => (float) ($m->wishi?->monthly_contribution ?? 0),
                'total_members' => (int) ($m->wishi?->total_members ?? 0),
                'member_capacity' => $m->wishi ? $m->wishi->memberCapacity() : 0,
                'duration_months' => (int) ($m->wishi?->duration_months ?? 0),
                'total_pool' => (float) ($m->wishi?->totalPool() ?? 0),
                'cycle_type' => $m->wishi?->cycle_type,
                'invited_at' => optional($m->created_at)?->toIso8601String(),
            ])->values();

        // Joined active WISHIs summary + expected-dues total over their lifetime
        $activeWishis = Wishi::whereIn('id', $activeWishiIds)->get([
            'id', 'uuid', 'name', 'monthly_contribution', 'duration_months', 'current_cycle', 'cycle_type',
        ]);
        $upcomingTotal = 0.0;
        foreach ($activeWishis as $w) {
            $cyclesLeft = max(0, (int) $w->duration_months - (int) $w->current_cycle);
            $upcomingTotal += (float) $w->monthly_contribution * $cyclesLeft;
        }
        $joinedWishis = $activeWishis->map(fn ($w) => [
            'uuid' => $w->uuid,
            'name' => $w->name,
            'cycle_type' => $w->cycle_type,
            'monthly' => (float) $w->monthly_contribution,
            'duration_months' => (int) $w->duration_months,
            'current_cycle' => (int) $w->current_cycle,
            'cycles_left' => max(0, (int) $w->duration_months - (int) $w->current_cycle),
            'remaining_dues' => (float) $w->monthly_contribution * max(0, (int) $w->duration_months - (int) $w->current_cycle),
        ])->values();

        return response()->json([
            'active_wishis_count' => $activeWishiIds->count(),
            'created_wishis_count' => $createdCount,
            'total_contributed' => (float) $totalContributed,
            'total_won' => (float) $totalWon,
            'credit_score' => (int) $user->credit_score,
            'trust_level' => $user->trust_level,
            'active_cycles_count' => $cyclesActive,
            'upcoming_total_dues' => $upcomingTotal,
            'pending_invitations' => $invitations,
            'upcoming_wishi_openings' => $upcomingOpenings,
            'joinable_wishis' => $joinable,
            'joined_wishis' => $joinedWishis,
            'upcoming_payments' => $upcomingPayments->map(fn ($c) => [
                'id' => $c->id,
                'amount' => (float) $c->amount,
                'status' => $c->status,
                'due_date' => $c->due_date->toDateString(),
                'wishi' => $c->wishi ? ['id' => $c->wishi->id, 'uuid' => $c->wishi->uuid, 'name' => $c->wishi->name] : null,
                'cycle_number' => $c->cycle?->cycle_number,
            ]),
        ]);
    }
}
