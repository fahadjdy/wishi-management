<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Cycle;
use App\Models\User;
use App\Models\Wishi;
use App\Models\WishiMember;
use App\Policies\AuditLogPolicy;
use App\Policies\CyclePolicy;
use App\Policies\WishiMemberPolicy;
use App\Policies\WishiPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Password::defaults(function () {
            return Password::min(10)
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });

        Gate::policy(Wishi::class, WishiPolicy::class);
        Gate::policy(Cycle::class, CyclePolicy::class);
        Gate::policy(WishiMember::class, WishiMemberPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();
            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('sensitive', function (Request $request) {
            $limit = app()->environment('local') ? 60 : 15;
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('bid', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });
    }
}
