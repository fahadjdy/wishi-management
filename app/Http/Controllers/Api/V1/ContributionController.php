<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contribution\RecordContributionRequest;
use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\Wishi;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionController extends Controller
{
    public function __construct(protected ContributionService $service) {}

    public function index(Request $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $this->authorize('view', $cycle);
        $contributions = $cycle->contributions()->with('user')->get();
        return response()->json(['data' => ContributionResource::collection($contributions)]);
    }

    public function store(RecordContributionRequest $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $userId = (int) ($request->input('user_id') ?? $request->user()->id);

        $contribution = Contribution::where('cycle_id', $cycle->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $isAdmin = (int) $wishi->created_by === (int) $request->user()->id;
        $isOwn = (int) $contribution->user_id === (int) $request->user()->id;
        abort_unless($isAdmin || $isOwn, 403, 'You cannot record this payment.');

        $updated = $this->service->recordPayment($contribution, $request->user(), $request->validated());
        $updated->load('user');
        return response()->json(['data' => new ContributionResource($updated)]);
    }

    protected function scope(Wishi $wishi, Cycle $cycle): void
    {
        abort_unless((int) $cycle->wishi_id === (int) $wishi->id, 404);
    }
}
