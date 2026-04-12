<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tender\PlaceBidRequest;
use App\Http\Resources\TenderResource;
use App\Models\Cycle;
use App\Models\Wishi;
use App\Services\TenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderController extends Controller
{
    public function __construct(protected TenderService $service) {}

    public function index(Request $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $this->authorize('view', $cycle);
        $user = $request->user();
        $isAdmin = (int) $wishi->created_by === (int) $user->id;
        $closed = $cycle->tender_closes_at && $cycle->tender_closes_at->isPast();

        $bids = $cycle->tenders()->with('user')->orderBy('bid_amount')->get();

        return response()->json([
            'data' => TenderResource::collection($bids),
            'meta' => [
                'window_closed' => (bool) $closed,
                'is_admin' => $isAdmin,
                'bid_count' => $bids->count(),
            ],
        ]);
    }

    public function store(PlaceBidRequest $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);
        $tender = $this->service->placeBid($cycle, $request->user(), (float) $request->input('bid_amount'));
        return response()->json(['data' => new TenderResource($tender)], 201);
    }

    protected function scope(Wishi $wishi, Cycle $cycle): void
    {
        abort_unless((int) $cycle->wishi_id === (int) $wishi->id, 404);
    }
}
