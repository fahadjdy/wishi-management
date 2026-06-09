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
     * Clears cached config/route/view so .env edits actually take effect.
     * On shared hosting a stale bootstrap/cache/config.php silently ignores
     * .env changes — this is usually why "I edited .env but nothing changed".
     */
    public function clear(): Response
    {
        $out = [];
        foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
                $out[] = "{$cmd}: " . trim(Artisan::output());
            } catch (\Throwable $e) {
                $out[] = "{$cmd}: ERROR — " . $e->getMessage();
            }
        }

        return $this->text(implode("\n", $out));
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
        $out[] = "\n--- last error in log ({$log}) ---";
        if (is_file($log)) {
            $lines = preg_split('/\r?\n/', (string) file_get_contents($log));
            $lastErr = null;
            foreach ($lines as $i => $l) {
                if (strpos($l, '.ERROR:') !== false) {
                    $lastErr = $i;
                }
            }
            if ($lastErr !== null) {
                // Header line carries the exception class + message + origin
                // file:line; the next frames show where it was thrown.
                $out[] = implode("\n", array_slice($lines, $lastErr, 30));
            } else {
                $out[] = substr(implode("\n", $lines), -6000);
            }
        } else {
            $out[] = '(no laravel.log yet)';
        }

        // Writable storage dirs (FTP deploy excludes these, so they may be
        // missing on the server — a common cause of session/view 500s).
        $out[] = "\n--- storage dirs ---";
        foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs'] as $d) {
            $p = storage_path($d);
            $out[] = "{$d}: " . (is_dir($p) ? (is_writable($p) ? 'ok (writable)' : 'EXISTS but NOT writable') : 'MISSING');
        }

        return $this->text(implode("\n", $out));
    }

    protected function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
