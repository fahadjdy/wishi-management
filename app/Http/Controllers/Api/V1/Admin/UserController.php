<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        // Active WISHI memberships — everything the admin needs to understand
        // this member's current obligations in one view.
        $memberships = \App\Models\WishiMember::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->with('wishi:id,uuid,name,status,monthly_contribution,current_cycle,duration_months,total_members,cycle_type,start_date')
            ->get()
            ->filter(fn ($m) => $m->wishi && in_array($m->wishi->status, ['planned', 'active', 'draft'], true))
            ->map(fn ($m) => [
                'wishi_uuid' => $m->wishi->uuid,
                'wishi_name' => $m->wishi->name,
                'wishi_status' => $m->wishi->status,
                'token_no' => $m->token_no,
                'membership_status' => $m->status,
                'monthly_contribution' => (float) $m->wishi->monthly_contribution,
                'current_cycle' => (int) $m->wishi->current_cycle,
                'duration_months' => (int) $m->wishi->duration_months,
                'total_members' => (int) $m->wishi->total_members,
                'cycle_type' => $m->wishi->cycle_type,
                'has_won' => (bool) $m->has_won,
                'won_in_cycle' => $m->won_in_cycle,
            ])->values();

        // All unpaid dues across every WISHI they're in — admin can mark-paid
        // straight from the member profile without hunting through cycle pages.
        // Filter by paid_at IS NULL — status='late' alone would also match
        // already-paid-after-due contributions (see FLOW.md §9 canonical rule).
        $pendingContributions = \App\Models\Contribution::where('user_id', $user->id)
            ->whereNull('paid_at')
            ->whereIn('status', ['pending', 'late'])
            ->with('wishi:id,uuid,name', 'cycle:id,cycle_number,status,paid_out_at,winner_id,selection_method')
            ->orderBy('due_date')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'wishi_uuid' => $c->wishi?->uuid,
                'wishi_name' => $c->wishi?->name,
                'cycle_id' => $c->cycle_id,
                'cycle_number' => $c->cycle?->cycle_number,
                'amount' => (float) $c->amount,
                'due_date' => $c->due_date?->toDateString(),
                'status' => $c->status,
            ])->values();

        // Recently-paid contributions — needed so admin can Undo a mistaken
        // "Mark as paid" action directly from the member profile.
        $paidContributions = \App\Models\Contribution::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'late'])
            ->whereNotNull('paid_at')
            ->with('wishi:id,uuid,name', 'cycle:id,cycle_number,status,paid_out_at,winner_id,selection_method')
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'wishi_uuid' => $c->wishi?->uuid,
                'wishi_name' => $c->wishi?->name,
                'cycle_id' => $c->cycle_id,
                'cycle_number' => $c->cycle?->cycle_number,
                'amount' => (float) $c->amount,
                'due_date' => $c->due_date?->toDateString(),
                'paid_at' => optional($c->paid_at)?->toIso8601String(),
                'status' => $c->status,
                // Undo is allowed only while the cycle is still open and no
                // winner has been announced (or it's cycle #1 organizer payout).
                'can_undo' => ! $c->cycle?->paid_out_at
                    && ($c->cycle?->selection_method === 'organizer_payout' || ! $c->cycle?->winner_id),
            ])->values();

        return response()->json([
            'data' => new AdminUserResource($user),
            'active_wishis' => $memberships,
            'pending_contributions' => $pendingContributions,
            'paid_contributions' => $paidContributions,
            'totals' => [
                'active_wishis_count' => $memberships->count(),
                'pending_dues' => (float) $pendingContributions->sum('amount'),
                'pending_count' => $pendingContributions->count(),
            ],
        ]);
    }

    /**
     * Platform admin creates a member account and manually assigns credentials.
     * Self-registration is disabled — only this path creates new users.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'credit_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user = new User;
        $user->name = $data['name'];
        $user->email = strtolower($data['email']);
        $user->phone = $data['phone'] ?? null;
        $user->password = $data['password']; // Hashed by User::$casts
        $user->credit_score = $data['credit_score'] ?? 70;
        $user->trust_level = app(\App\Services\CreditScoreService::class)->resolveTrustLevel($user->credit_score);
        $user->is_admin = (bool) ($data['is_admin'] ?? false);
        $user->email_verified_at = now();
        $user->save();

        $this->audit->log(null, $request->user(), 'user_created_by_admin',
            "Admin created account for {$user->email}" . ($user->is_admin ? ' (platform admin)' : ''),
            ['target_user_id' => $user->id, 'is_admin' => $user->is_admin]);

        return response()->json(['data' => new AdminUserResource($user)], 201);
    }

    /**
     * Admin edits a member's profile — name, email, phone, WhatsApp, and
     * avatar photo. Avatar upload uses the 'public' disk (served under
     * /storage). Password is not touched here — use the `password` action
     * below. Email change keeps the same user row; member must be notified
     * out-of-band because it's also their login identifier.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email:rfc', 'max:160', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'whatsapp_number' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'avatar' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);

        $user = User::withTrashed()->findOrFail($id);

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = strtolower($data['email']);
        }
        if ($request->exists('phone')) {
            $user->phone = $data['phone'] ?? null;
        }
        if ($request->exists('whatsapp_number')) {
            $user->whatsapp_number = $data['whatsapp_number'] ?? null;
        }

        if ($request->boolean('remove_avatar') && $user->avatar_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            }
            $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        $this->audit->log(null, $request->user(), 'user_updated_by_admin',
            "Admin updated profile for {$user->email}",
            ['target_user_id' => $user->id, 'fields' => array_keys($data)]);

        return response()->json(['data' => new AdminUserResource($user->fresh())]);
    }

    /**
     * Admin resets a member's password to a new value. Used when a member
     * forgets their password — since self-registration is disabled and there
     * is no email-based reset flow, the admin sets a fresh credential and
     * shares it out-of-band (WhatsApp/SMS). Admins cannot reset their own
     * password here; they must use the self-service `/me/password` path.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        if ($id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'Use your own Profile page to change your password.',
            ]);
        }

        $user = User::withTrashed()->findOrFail($id);
        $user->password = $data['password']; // hashed by User::$casts
        $user->save();

        $this->audit->log(null, $request->user(), 'user_password_reset_by_admin',
            "Admin reset password for {$user->email}",
            ['target_user_id' => $user->id]);

        return response()->json(['message' => 'Password reset. Share the new credentials with the member manually.']);
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
