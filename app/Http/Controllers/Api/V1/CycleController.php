<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cycle\PayoutRequest;
use App\Http\Requests\Cycle\SelectWinnerRequest;
use App\Http\Requests\Cycle\SurplusRequest;
use App\Http\Resources\CycleResource;
use App\Http\Resources\PayoutResource;
use App\Models\Cycle;
use App\Models\Wishi;
use App\Services\CycleService;
use App\Services\PayoutService;
use App\Services\SurplusService;
use App\Services\WinnerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CycleController extends Controller
{
    public function __construct(
        protected CycleService $cycleService,
        protected WinnerSelectionService $winnerService,
        protected SurplusService $surplusService,
        protected PayoutService $payoutService,
    ) {}

    public function index(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('view', $wishi);
        $cycles = $wishi->cycles()->with('winner')->orderBy('cycle_number')->get();
        return response()->json(['data' => CycleResource::collection($cycles)]);
    }

    public function show(Request $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $this->authorize('view', $cycle);
        $cycle->load('winner');
        return response()->json(['data' => new CycleResource($cycle)]);
    }

    public function next(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('update', $wishi);
        $cycle = $this->cycleService->advanceToNextCycle($wishi, $request->user());
        $cycle->load('winner');
        return response()->json(['data' => new CycleResource($cycle)], 201);
    }

    public function selectWinner(SelectWinnerRequest $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $method = $request->input('method');
        if ($method === 'manual') {
            $cycle = $this->winnerService->manualSelectWinner($cycle, (int) $request->input('user_id'), $request->user(), $request->input('reason'));
        } else {
            $cycle = $cycle->mode === 'tender'
                ? $this->winnerService->selectTenderWinner($cycle, $request->user())
                : $this->winnerService->selectRandomWinner($cycle, $request->user());
        }
        $cycle->load('winner');
        return response()->json(['data' => new CycleResource($cycle)]);
    }

    public function surplus(SurplusRequest $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $cycle = $this->surplusService->handle($cycle, $request->input('action'), $request->user(), $request->input('recipient_id'), $request->input('reason'));
        $cycle->load('winner');
        return response()->json(['data' => new CycleResource($cycle)]);
    }

    public function payout(PayoutRequest $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $payout = $this->payoutService->record($cycle, $request->user(), $request->validated());
        return response()->json(['data' => new PayoutResource($payout)], 201);
    }

    protected function scope(Wishi $wishi, Cycle $cycle): void
    {
        abort_unless((int) $cycle->wishi_id === (int) $wishi->id, 404);
    }
}
