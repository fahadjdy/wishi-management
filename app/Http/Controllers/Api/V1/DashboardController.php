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

        $activeWishiIds = WishiMember::where('user_id', $user->id)
            ->whereIn('status', ['approved', 'active'])
            ->whereHas('wishi', fn ($q) => $q->where('status', 'active'))
            ->pluck('wishi_id');

        $totalContributed = Contribution::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'late'])
            ->sum('amount');

        $totalWon = Payout::where('user_id', $user->id)->sum('amount');

        $upcomingPayments = Contribution::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'late'])
            ->orderBy('due_date')
            ->with('wishi:id,uuid,name', 'cycle:id,cycle_number')
            ->limit(10)
            ->get();

        $cyclesActive = Cycle::whereIn('wishi_id', $activeWishiIds)
            ->whereIn('status', ['contribution_open', 'bidding_open', 'selection_pending'])
            ->count();

        $createdCount = Wishi::where('created_by', $user->id)->where('status', 'active')->count();

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
                'duration_months' => (int) ($m->wishi?->duration_months ?? 0),
                'total_pool' => (float) (($m->wishi?->monthly_contribution ?? 0) * ($m->wishi?->total_members ?? 0)),
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
