<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'in:admin,member'],
            'status' => ['nullable', 'in:active,locked,deleted'],
            'sort' => ['nullable', 'in:name,credit_score,created_at,last_login_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = User::query()->withTrashed();

        if ($q = $request->input('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        if ($request->input('role') === 'admin') $query->where('is_admin', true);
        if ($request->input('role') === 'member') $query->where('is_admin', false);

        if ($request->input('status') === 'locked') $query->whereNotNull('locked_until')->where('locked_until', '>', now());
        if ($request->input('status') === 'deleted') $query->whereNotNull('deleted_at');
        if ($request->input('status') === 'active') $query->whereNull('deleted_at')->where(function ($w) {
            $w->whereNull('locked_until')->orWhere('locked_until', '<=', now());
        });

        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');
        $query->orderBy($sort, $direction);

        $users = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => AdminUserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'per_page' => $users->perPage(),
            ],
            'summary' => [
                'total_users' => User::withTrashed()->count(),
                'admins' => User::where('is_admin', true)->count(),
                'locked' => User::whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
                'deleted' => User::onlyTrashed()->count(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        return response()->json(['data' => new AdminUserResource($user)]);
    }

    public function toggleAdmin(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot change your own admin status.']);
        }
        $user->is_admin = ! $user->is_admin;
        $user->save();
        $this->audit->log(null, $request->user(), $user->is_admin ? 'admin_granted' : 'admin_revoked',
            "{$user->name} " . ($user->is_admin ? 'promoted to' : 'demoted from') . ' platform admin',
            ['target_user_id' => $user->id]);
        return response()->json(['data' => new AdminUserResource($user)]);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:43200'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot lock yourself out.']);
        }
        $minutes = (int) $request->input('minutes', 60);
        $user->locked_until = now()->addMinutes($minutes);
        $user->save();
        $this->audit->log(null, $request->user(), 'user_locked',
            "{$user->name} locked for {$minutes} min",
            ['target_user_id' => $user->id, 'reason' => $request->input('reason'), 'minutes' => $minutes]);
        return response()->json(['data' => new AdminUserResource($user)]);
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->locked_until = null;
        $user->failed_login_attempts = 0;
        $user->save();
        $this->audit->log(null, $request->user(), 'user_unlocked', "{$user->name} unlocked",
            ['target_user_id' => $user->id]);
        return response()->json(['data' => new AdminUserResource($user)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot delete yourself.']);
        }
        $hasActive = $user->wishiMemberships()->whereIn('status', ['approved', 'active'])->whereHas('wishi', fn ($q) => $q->where('status', 'active'))->exists();
        if ($hasActive) {
            throw ValidationException::withMessages(['user' => 'User has active WISHI memberships and cannot be deleted.']);
        }
        $user->delete();
        $this->audit->log(null, $request->user(), 'user_deleted', "{$user->name} soft-deleted",
            ['target_user_id' => $user->id]);
        return response()->json(['message' => 'User deleted.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();
        $this->audit->log(null, $request->user(), 'user_restored', "{$user->name} restored",
            ['target_user_id' => $user->id]);
        return response()->json(['data' => new AdminUserResource($user)]);
    }

    public function adjustCredit(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'points' => ['required', 'integer', 'min:-100', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $user = User::findOrFail($id);
        $service = app(\App\Services\CreditScoreService::class);
        $log = $service->updateScore($user, 'manual_adjust', null, null, (int) $request->input('points'), $request->input('reason'));
        $this->audit->log(null, $request->user(), 'credit_adjusted',
            "{$user->name} credit adjusted by " . $request->input('points'),
            ['target_user_id' => $user->id, 'points' => (int) $request->input('points'), 'reason' => $request->input('reason')]);
        return response()->json([
            'data' => new AdminUserResource($user->fresh()),
            'log' => ['score_before' => $log->score_before, 'score_after' => $log->score_after],
        ]);
    }
}
