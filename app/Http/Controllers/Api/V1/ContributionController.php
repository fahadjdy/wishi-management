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

        $viewer = $request->user();
        $isAdmin = (int) $wishi->created_by === (int) $viewer->id;

        // Privacy: non-admin members see only their own contribution row. They do NOT
        // see who else has/hasn't paid — that's admin-only information.
        $query = $cycle->contributions()->with('user');
        if (! $isAdmin) {
            $query->where('user_id', $viewer->id);
        }
        $contributions = $query->get();

        return response()->json([
            'data' => ContributionResource::collection($contributions),
            'meta' => [
                'restricted' => ! $isAdmin,
                'paid_count' => $isAdmin ? $cycle->contributions()->whereNotNull('paid_at')->count() : null,
                'total_count' => $isAdmin ? $cycle->contributions()->count() : null,
            ],
        ]);
    }

    public function store(RecordContributionRequest $request, Wishi $wishi, Cycle $cycle): JsonResponse
    {
        $this->scope($wishi, $cycle);

        // Only the WISHI admin can record any payment. Members cannot self-report payment —
        // this keeps the audit trail authoritative and prevents fraud.
        $isAdmin = (int) $wishi->created_by === (int) $request->user()->id;
        abort_unless($isAdmin, 403, 'Only the WISHI admin can record contribution payments.');

        $userId = (int) ($request->input('user_id') ?? $request->user()->id);
        $contribution = Contribution::where('cycle_id', $cycle->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $updated = $this->service->recordPayment($contribution, $request->user(), $request->validated());
        $updated->load('user');
        return response()->json(['data' => new ContributionResource($updated)]);
    }

    /**
     * Authenticated user's own contribution history for a single WISHI, across
     * every cycle they have a contribution record in. Ordered chronologically
     * (oldest cycle first) so the UI can render it as a payment timeline.
     *
     * Returns an empty collection for viewers who are not members (e.g. the
     * WISHI admin, or a user who hasn't joined). That's the right behavior —
     * admins don't have contribution records in their own WISHI (organizer
     * payout handles cycle #1), and non-members simply have nothing to show.
     */
    public function myHistory(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('view', $wishi);

        // Qualify `wishi_id` / `user_id` with the table name — both `contributions`
        // and `cycles` carry a `wishi_id` column, so an unqualified WHERE is
        // ambiguous once we JOIN cycles for cycle_number ordering.
        $contributions = Contribution::query()
            ->join('cycles', 'cycles.id', '=', 'contributions.cycle_id')
            ->where('contributions.wishi_id', $wishi->id)
            ->where('contributions.user_id', $request->user()->id)
            ->with('cycle')
            ->orderBy('cycles.cycle_number')
            ->select('contributions.*')
            ->get();

        return response()->json([
            'data' => ContributionResource::collection($contributions),
            'meta' => [
                'paid_count' => $contributions->whereNotNull('paid_at')->count(),
                'pending_count' => $contributions->whereNull('paid_at')->count(),
                'total_paid' => (float) $contributions->whereNotNull('paid_at')->sum('amount'),
                'total_pending' => (float) $contributions->whereNull('paid_at')->sum('amount'),
            ],
        ]);
    }

    public function revert(Request $request, Wishi $wishi, Cycle $cycle, Contribution $contribution): JsonResponse
    {
        $this->scope($wishi, $cycle);
        abort_unless((int) $contribution->cycle_id === (int) $cycle->id, 404);

        $isAdmin = (int) $wishi->created_by === (int) $request->user()->id;
        abort_unless($isAdmin, 403, 'Only the WISHI admin can undo a payment.');

        $updated = $this->service->revertPayment($contribution, $request->user());
        $updated->load('user');
        return response()->json(['data' => new ContributionResource($updated)]);
    }

    protected function scope(Wishi $wishi, Cycle $cycle): void
    {
        abort_unless((int) $cycle->wishi_id === (int) $wishi->id, 404);
    }
}
