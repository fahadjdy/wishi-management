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
        return response()->json(['data' => WishiMemberResource::collection($members)]);
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
