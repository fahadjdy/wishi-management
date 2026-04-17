<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates (or ensures) a single platform-admin account.
 *
 * Idempotent — safe to re-run any number of times. On re-run, only the
 * `is_admin` flag and soft-delete state are reconciled; the existing
 * password is NOT overwritten so a working admin can't be locked out by a
 * fat-finger seed call.
 *
 * Environment overrides (with defaults):
 *   ADMIN_SEED_EMAIL    = admin@example.com
 *   ADMIN_SEED_PASSWORD = password
 *   ADMIN_SEED_NAME     = Platform Admin
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_SEED_EMAIL', 'admin@example.com');
        $password = (string) env('ADMIN_SEED_PASSWORD', 'password');
        $name = (string) env('ADMIN_SEED_NAME', 'Platform Admin');

        // withTrashed so a previously soft-deleted admin row is reused rather
        // than colliding on the unique email index.
        $user = User::withTrashed()->where('email', $email)->first();
        $existed = (bool) $user;

        if (! $existed) {
            $user = new User();
            $user->name = $name;
            $user->email = $email;
            // Auto-hashed via the `password => 'hashed'` cast on User.
            $user->password = $password;
            $user->credit_score = 100;
            $user->trust_level = 'excellent';
            $user->email_verified_at = now();
        }

        // Direct assignment — is_admin is intentionally not in $fillable, so
        // this bypasses mass-assignment protection.
        $user->is_admin = true;
        $user->deleted_at = null;
        $user->save();

        $status = $existed ? 'updated' : 'created';
        $this->command?->info("Admin {$status}: {$email}");
    }
}
