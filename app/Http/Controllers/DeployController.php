<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

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

    protected function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
