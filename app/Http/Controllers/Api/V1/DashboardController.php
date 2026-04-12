<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
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

        return response()->json([
            'active_wishis_count' => $activeWishiIds->count(),
            'created_wishis_count' => $createdCount,
            'total_contributed' => (float) $totalContributed,
            'total_won' => (float) $totalWon,
            'credit_score' => (int) $user->credit_score,
            'trust_level' => $user->trust_level,
            'active_cycles_count' => $cyclesActive,
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
