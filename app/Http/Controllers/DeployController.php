<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Remote deploy endpoints for shared hosting where CLI/SSH isn't available.
 *
 * Every route here is gated by a token stored in `.env` as DEPLOY_SECRET.
 * If that key is missing (or the submitted token doesn't match), the endpoint
 * returns 404 so its existence can't be probed anonymously.
 *
 * Usage:
 *   GET /deploy/migrate?token=<secret>      → runs `artisan migrate --force`
 *   GET /deploy/seed-admin?token=<secret>   → runs the AdminSeeder
 *
 * Response is plain text — the console output from Artisan so the caller
 * can see exactly what happened.
 */
class DeployController extends Controller
{
    public function migrate(Request $request): Response
    {
        $this->assertToken($request);

        // --force bypasses the "running in production?" confirmation that
        // would otherwise hang artisan when run non-interactively.
        $exit = Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();

        return $this->text("migrate exit={$exit}\n\n{$output}");
    }

    public function seedAdmin(Request $request): Response
    {
        $this->assertToken($request);

        $exit = Artisan::call('db:seed', [
            '--class' => \Database\Seeders\AdminSeeder::class,
            '--force' => true,
        ]);
        $output = Artisan::output();

        return $this->text("seed-admin exit={$exit}\n\n{$output}");
    }

    /**
     * Constant-time token check. Missing secret = 404 (not 401/403) so the
     * endpoint is invisible when the server hasn't been configured.
     */
    protected function assertToken(Request $request): void
    {
        $secret = (string) env('DEPLOY_SECRET', '');
        if ($secret === '') {
            throw new NotFoundHttpException();
        }
        $provided = (string) $request->input('token', '');
        if (! hash_equals($secret, $provided)) {
            throw new NotFoundHttpException();
        }
    }

    protected function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
