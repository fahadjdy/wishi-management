<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'credit_score' => 70,
            'trust_level' => 'good',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        $this->audit->log(null, $user, 'user_registered', 'User registered and logged in');

        return response()->json([
            'message' => 'Registration successful.',
            'user' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower($request->input('email'));
        $key = 'login:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($key, 900); // 15 min decay
            if ($user) {
                $user->increment('failed_login_attempts');
                if ($user->failed_login_attempts >= 10) {
                    $user->locked_until = now()->addMinutes(30);
                    $user->save();
                }
            }
            throw ValidationException::withMessages([
                'email' => 'Invalid credentials.',
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => 'Account is temporarily locked. Try again later.',
            ]);
        }

        RateLimiter::clear($key);
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        Auth::login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        $this->audit->log(null, $user, 'user_login', 'User logged in');

        return response()->json([
            'message' => 'Login successful.',
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->audit->log(null, $user, 'user_logout', 'User logged out');
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    /**
     * Self-service password change. Requires the current password as a
     * confirmation step so a stolen session can't silently rotate creds.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required', 'string', 'min:10', 'max:72', 'confirmed',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
        ], [
            'password.regex' => 'Password must include upper, lower, number and a symbol.',
        ]);

        $user = $request->user();
        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        $user->password = $data['password']; // cast to hashed
        $user->save();

        $this->audit->log(null, $user, 'password_changed', 'User changed their own password');

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Self-service profile update. Members can only rotate their avatar.
     * Platform admins can additionally edit their own email / phone /
     * WhatsApp here — members still cannot (those stay admin-managed via
     * `/admin/users/{id}`). Name remains admin-managed for everyone.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isPlatformAdmin();

        $rules = [
            'avatar' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
        if ($isAdmin) {
            $rules['email'] = ['sometimes', 'email:rfc', 'max:160', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)];
            $rules['phone'] = ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/', \Illuminate\Validation\Rule::unique('users', 'phone')->ignore($user->id)];
            $rules['whatsapp_number'] = ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'];
        }

        $data = $request->validate($rules);

        // Members still can't self-edit contact fields — reject instead of silently ignoring.
        if (! $isAdmin && ($request->filled('phone') || $request->filled('whatsapp_number') || $request->filled('name') || $request->filled('email'))) {
            throw ValidationException::withMessages([
                'profile' => 'Name, email, phone and WhatsApp can only be changed by the platform admin.',
            ]);
        }
        if ($isAdmin && $request->filled('name')) {
            throw ValidationException::withMessages(['name' => 'Name is not editable here.']);
        }

        $contactChanges = [];
        if ($isAdmin) {
            if (array_key_exists('email', $data)) {
                $new = strtolower($data['email']);
                if ($new !== $user->email) { $contactChanges['email'] = ['from' => $user->email, 'to' => $new]; $user->email = $new; }
            }
            if ($request->exists('phone')) {
                $new = $data['phone'] ?? null;
                if ($new !== $user->phone) { $contactChanges['phone'] = ['from' => $user->phone, 'to' => $new]; $user->phone = $new; }
            }
            if ($request->exists('whatsapp_number')) {
                $new = $data['whatsapp_number'] ?? null;
                if ($new !== $user->whatsapp_number) { $contactChanges['whatsapp_number'] = ['from' => $user->whatsapp_number, 'to' => $new]; $user->whatsapp_number = $new; }
            }
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

        if ($request->hasFile('avatar') || $request->boolean('remove_avatar')) {
            $this->audit->log(null, $user, 'profile_photo_updated', 'User updated their profile photo');
        }
        if (! empty($contactChanges)) {
            $this->audit->log(null, $user, 'admin_self_contact_updated',
                'Admin updated own contact details',
                ['changes' => $contactChanges]);
        }

        return response()->json(['user' => new UserResource($user->fresh())]);
    }
}
