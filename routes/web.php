<?php

use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

// Remote deploy endpoints — gated by DEPLOY_SECRET in .env. They MUST be
// registered before the SPA catch-all, otherwise the Vue shell swallows them.
// See DeployController for security model.
Route::prefix('deploy')->group(function () {
    Route::get('migrate', [DeployController::class, 'migrate']);
    Route::get('seed-admin', [DeployController::class, 'seedAdmin']);
});

// SPA catch-all. Every non-API, non-sanctum, non-storage, non-up, non-deploy
// path returns the Vue shell so the client-side router can take over.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|storage|up|deploy).*$');
