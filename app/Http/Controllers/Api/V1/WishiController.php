<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishi\StoreWishiRequest;
use App\Http\Requests\Wishi\UpdateWishiRequest;
use App\Http\Resources\WishiResource;
use App\Models\Wishi;
use App\Services\MembershipService;
use App\Services\WishiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishiController extends Controller
{
    public function __construct(
        protected WishiService $service,
        protected MembershipService $membership,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:draft,active,completed,cancelled'],
            'role' => ['nullable', 'string', 'in:admin,member'],
            'cycle_type' => ['nullable', 'string', 'in:random,tender,hybrid'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,name'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $q = Wishi::query()
            ->withCount(['members', 'activeMembers'])
            ->with('creator');

        // Scope to wishis the user can see: either they created it, or they're a member.
        $role = $request->input('role');
        if ($role === 'admin') {
            $q->where('created_by', $user->id);
        } elseif ($role === 'member') {
            $q->whereHas('members', fn ($m) => $m->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'approved', 'active']));
        } else {
            $q->where(function ($w) use ($user) {
                $w->where('created_by', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)
                        ->whereIn('status', ['pending', 'approved', 'active']));
            });
        }

        if ($search = trim((string) $request->input('q'))) {
            $q->where('name', 'like', '%' . $search . '%');
        }
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($cycleType = $request->input('cycle_type')) {
            $q->where('cycle_type', $cycleType);
        }

        $sort = $request->input('sort', 'newest');
        match ($sort) {
            'oldest' => $q->orderBy('created_at'),
            'name' => $q->orderBy('name'),
            default => $q->orderByDesc('created_at'),
        };

        $wishis = $q->paginate(20)->withQueryString();

        // Summary counts (pre-search, post-role scope) so the filter chips can show totals.
        $baseCounts = Wishi::query();
        if ($role === 'admin') {
            $baseCounts->where('created_by', $user->id);
        } elseif ($role === 'member') {
            $baseCounts->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->whereIn('status', ['pending', 'approved', 'active']));
        } else {
            $baseCounts->where(function ($w) use ($user) {
                $w->where('created_by', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->whereIn('status', ['pending', 'approved', 'active']));
            });
        }
        $summary = (clone $baseCounts)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        return response()->json([
            'data' => WishiResource::collection($wishis),
            'meta' => [
                'current_page' => $wishis->currentPage(),
                'last_page' => $wishis->lastPage(),
                'total' => $wishis->total(),
                'per_page' => $wishis->perPage(),
            ],
            'counts' => [
                'all' => (int) ($summary['draft'] ?? 0) + (int) ($summary['active'] ?? 0) + (int) ($summary['completed'] ?? 0) + (int) ($summary['cancelled'] ?? 0),
                'draft' => (int) ($summary['draft'] ?? 0),
                'active' => (int) ($summary['active'] ?? 0),
                'completed' => (int) ($summary['completed'] ?? 0),
                'cancelled' => (int) ($summary['cancelled'] ?? 0),
            ],
        ]);
    }

    public function store(StoreWishiRequest $request): JsonResponse
    {
        // New WISHIs always start as draft — activation requires full capacity.
        $wishi = $this->service->create($request->user(), $request->validated());
        $wishi = $wishi->fresh()->loadCount(['members', 'activeMembers'])->load('creator');
        return response()->json(['data' => new WishiResource($wishi)], 201);
    }

    public function show(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('view', $wishi);
        $wishi->loadCount(['members', 'activeMembers'])->load('creator');
        return response()->json(['data' => new WishiResource($wishi)]);
    }

    public function update(UpdateWishiRequest $request, Wishi $wishi): JsonResponse
    {
        $updated = $this->service->updateSettings($wishi, $request->user(), $request->validated());
        $updated->loadCount(['members', 'activeMembers'])->load('creator');
        return response()->json(['data' => new WishiResource($updated)]);
    }

    public function activate(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('update', $wishi);
        $updated = $this->service->activate($wishi, $request->user());
        $updated->loadCount(['members', 'activeMembers'])->load('creator');
        return response()->json(['data' => new WishiResource($updated)]);
    }

    public function join(Request $request, Wishi $wishi): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('join', $wishi);
        $member = $this->membership->requestJoin($wishi, $request->user());
        return response()->json([
            'message' => $member->status === 'approved' ? 'You have joined the WISHI.' : 'Join request sent.',
            'status' => $member->status,
        ], 201);
    }

    public function invite(Request $request, Wishi $wishi): JsonResponse
    {
        $this->authorize('manageMembers', $wishi);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $user = \App\Models\User::findOrFail($data['user_id']);
        $member = $this->membership->invite($wishi, $user, $request->user());
        return response()->json(['data' => new \App\Http\Resources\WishiMemberResource($member->load('user'))], 201);
    }

    public function acceptInvite(Request $request, Wishi $wishi): JsonResponse
    {
        $member = \App\Models\WishiMember::where('wishi_id', $wishi->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('invited_by_admin', true)
            ->firstOrFail();
        $updated = $this->membership->acceptInvite($member, $request->user());
        return response()->json(['data' => new \App\Http\Resources\WishiMemberResource($updated->load('user'))]);
    }

    public function declineInvite(Request $request, Wishi $wishi): JsonResponse
    {
        $member = \App\Models\WishiMember::where('wishi_id', $wishi->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('invited_by_admin', true)
            ->firstOrFail();
        $this->membership->declineInvite($member, $request->user(), $request->input('reason'));
        return response()->json(['message' => 'Invitation declined.']);
    }
}
