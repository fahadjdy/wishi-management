<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Remote deploy endpoints for shared hosting where CLI/SSH isn't available.
 *
 * NOTE: These endpoints are open (no token). Anyone who knows the URL can run
 * them, so comment out the routes in routes/web.php (or delete this file) once
 * the initial deploy is done.
 *
 * Usage:
 *   GET /deploy/migrate      → runs `artisan migrate --force`
 *   GET /deploy/seed-admin   → runs the AdminSeeder
 *   GET /deploy/diag         → health check: APP_KEY, DB, tables, recent log
 *
 * Response is plain text — the console output from Artisan so the caller
 * can see exactly what happened.
 */
class DeployController extends Controller
{
    public function migrate(): Response
    {
        // --force bypasses the "running in production?" confirmation that
        // would otherwise hang artisan when run non-interactively.
        $exit = Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();

        return $this->text("migrate exit={$exit}\n\n{$output}");
    }

    public function seedAdmin(): Response
    {
        $exit = Artisan::call('db:seed', [
            '--class' => \Database\Seeders\AdminSeeder::class,
            '--force' => true,
        ]);
        $output = Artisan::output();

        return $this->text("seed-admin exit={$exit}\n\n{$output}");
    }

    /**
     * Temporary health check to debug 500s on shared hosting. Each probe is
     * wrapped so the page itself never 500s — it reports the failure instead.
     * Remove this route once the deploy is healthy.
     */
    public function diag(): Response
    {
        $out = [];

        $out[] = 'APP_ENV:   ' . config('app.env');
        $out[] = 'APP_DEBUG: ' . (config('app.debug') ? 'true' : 'false');
        $out[] = 'APP_KEY:   ' . (config('app.key') ? 'set' : 'MISSING');
        $out[] = 'DB conn:   ' . config('database.default');
        $out[] = 'Sessions:  ' . config('session.driver');

        try {
            DB::connection()->getPdo();
            $out[] = 'DB connect: OK (database=' . DB::connection()->getDatabaseName() . ')';
        } catch (\Throwable $e) {
            $out[] = 'DB connect: FAILED — ' . $e->getMessage();
        }

        foreach (['migrations', 'users'] as $table) {
            try {
                $out[] = "table {$table}: " . DB::table($table)->count() . ' rows';
            } catch (\Throwable $e) {
                $out[] = "table {$table}: ERROR — " . $e->getMessage();
            }
        }

        $log = storage_path('logs/laravel.log');
        $out[] = "\n--- last log lines ({$log}) ---";
        if (is_file($log)) {
            $out[] = substr((string) file_get_contents($log), -6000);
        } else {
            $out[] = '(no laravel.log yet)';
        }

        return $this->text(implode("\n", $out));
    }

    protected function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
