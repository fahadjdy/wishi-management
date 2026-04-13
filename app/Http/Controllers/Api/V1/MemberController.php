<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WishiMemberResource;
use App\Models\Wishi;
use App\Models\WishiMember;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __construct(protected MembershipService $service) {}

    public function index(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('view', $wishi);

        $viewer = $request->user();
        $isAdmin = (int) $wishi->created_by === (int) $viewer->id;

        // Privacy rule: non-admin members cannot see the full member list.
        // They only see their own membership row (for self-service lookups).
        if (! $isAdmin) {
            $own = $wishi->members()
                ->where('user_id', $viewer->id)
                ->with('user')
                ->get();
            return response()->json([
                'data' => WishiMemberResource::collection($own),
                'meta' => ['restricted' => true, 'reason' => 'Only the WISHI admin can view the member list.'],
            ]);
        }

        $members = $wishi->members()->with('user')->orderByDesc('created_at')->get();
        $payload = WishiMemberResource::collection($members)->resolve($request);

        // Prepend the WISHI creator as a virtual "Member #1 (Admin)" entry. Admin
        // is NOT stored in wishi_members (FLOW.md §6) but per product rule the
        // organizer always appears first in the member list, marked as the
        // cycle-#1 organizer-payout winner.
        $creator = $wishi->creator()->first();
        if ($creator) {
            $firstCycle = $wishi->cycles()->where('cycle_number', 1)->first();
            array_unshift($payload, [
                'id' => 'admin-' . $creator->id,
                'wishi_id' => $wishi->id,
                'user_id' => $creator->id,
                'status' => 'approved',
                'is_admin' => true,
                'is_organizer_virtual' => true,
                'token_no' => null,
                'on_time_rate' => null,
                'on_time_count' => 0,
                'settled_count' => 0,
                'has_won' => (bool) ($firstCycle?->winner_id === $creator->id),
                'won_in_cycle' => $firstCycle?->winner_id === $creator->id ? 1 : null,
                'joined_at' => optional($wishi->created_at)?->toIso8601String(),
                'user' => [
                    'id' => $creator->id,
                    'name' => $creator->name,
                    'email' => $creator->email,
                    'credit_score' => (int) $creator->credit_score,
                    'trust_level' => $creator->trust_level,
                ],
            ]);
        }

        return response()->json(['data' => $payload]);
    }

    public function approve(Request $request, Wishi $wishi, WishiMember $member): JsonResponse
    {
        $this->scope($wishi, $member);
        $this->authorize('approve', $member);
        $updated = $this->service->approve($member, $request->user());
        $updated->load('user');
        return response()->json(['data' => new WishiMemberResource($updated)]);
    }

    public function reject(Request $request, Wishi $wishi, WishiMember $member): JsonResponse
    {
        $this->scope($wishi, $member);
        $this->authorize('reject', $member);
        $updated = $this->service->reject($member, $request->user(), $request->input('reason'));
        $updated->load('user');
        return response()->json(['data' => new WishiMemberResource($updated)]);
    }

    public function destroy(Request $request, Wishi $wishi, WishiMember $member): JsonResponse
    {
        $this->scope($wishi, $member);
        $this->authorize('remove', $member);
        $this->service->remove($member, $request->user(), $request->input('reason'));
        return response()->json(['message' => 'Member removed.']);
    }

    protected function scope(Wishi $wishi, WishiMember $member): void
    {
        abort_unless((int) $member->wishi_id === (int) $wishi->id, 404);
    }
}
