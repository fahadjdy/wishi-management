<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreditScoreLogResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Wishi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditScoreController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $logs = $user->creditScoreLogs()->orderByDesc('created_at')->limit(50)->get();
        return response()->json([
            'score' => (int) $user->credit_score,
            'trust_level' => $user->trust_level,
            'logs' => CreditScoreLogResource::collection($logs),
        ]);
    }

    public function memberScores(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('manageMembers', $wishi);
        $members = $wishi->members()->with('user')->get()
            ->map(fn ($m) => new UserSummaryResource($m->user));
        return response()->json(['data' => $members]);
    }
}
