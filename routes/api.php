<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContributionController;
use App\Http\Controllers\Api\V1\CreditScoreController;
use App\Http\Controllers\Api\V1\CycleController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\TenderController;
use App\Http\Controllers\Api\V1\WishiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        // Self-registration is disabled — members are created by platform admins only.
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/me/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/me/credit-score', [CreditScoreController::class, 'me']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::put('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        Route::get('/wishis', [WishiController::class, 'index']);
        Route::post('/wishis', [WishiController::class, 'store'])->middleware('throttle:sensitive');
        Route::get('/wishis/{wishi}', [WishiController::class, 'show']);
        Route::put('/wishis/{wishi}', [WishiController::class, 'update'])->middleware('throttle:sensitive');
        Route::post('/wishis/{wishi}/activate', [WishiController::class, 'activate'])->middleware('throttle:sensitive');
        Route::post('/wishis/{wishi}/join', [WishiController::class, 'join'])->middleware('throttle:sensitive');
        Route::post('/wishis/{wishi}/invite', [WishiController::class, 'invite'])->middleware('throttle:sensitive');
        Route::post('/wishis/{wishi}/accept-invite', [WishiController::class, 'acceptInvite'])->middleware('throttle:sensitive');
        Route::post('/wishis/{wishi}/decline-invite', [WishiController::class, 'declineInvite'])->middleware('throttle:sensitive');

        Route::get('/wishis/{wishi}/members', [MemberController::class, 'index']);
        Route::put('/wishis/{wishi}/members/{member}/approve', [MemberController::class, 'approve'])->middleware('throttle:sensitive');
        Route::put('/wishis/{wishi}/members/{member}/reject', [MemberController::class, 'reject'])->middleware('throttle:sensitive');
        Route::delete('/wishis/{wishi}/members/{member}', [MemberController::class, 'destroy'])->middleware('throttle:sensitive');
        Route::get('/wishis/{wishi}/members/credit-scores', [CreditScoreController::class, 'memberScores']);

        Route::get('/wishis/{wishi}/cycles', [CycleController::class, 'index']);
        Route::post('/wishis/{wishi}/cycles/next', [CycleController::class, 'next'])->middleware('throttle:sensitive');
        Route::get('/wishis/{wishi}/cycles/{cycle}', [CycleController::class, 'show']);
        Route::put('/wishis/{wishi}/cycles/{cycle}/select-winner', [CycleController::class, 'selectWinner'])->middleware('throttle:sensitive');
        Route::put('/wishis/{wishi}/cycles/{cycle}/select-multi-winners', [CycleController::class, 'selectMultiWinners'])->middleware('throttle:sensitive');
        Route::put('/wishis/{wishi}/cycles/{cycle}/surplus', [CycleController::class, 'surplus'])->middleware('throttle:sensitive');
        Route::put('/wishis/{wishi}/cycles/{cycle}/payout', [CycleController::class, 'payout'])->middleware('throttle:sensitive');

        Route::get('/wishis/{wishi}/cycles/{cycle}/contributions', [ContributionController::class, 'index']);
        Route::post('/wishis/{wishi}/cycles/{cycle}/contributions', [ContributionController::class, 'store'])->middleware('throttle:sensitive');

        Route::get('/wishis/{wishi}/cycles/{cycle}/tenders', [TenderController::class, 'index']);
        Route::post('/wishis/{wishi}/cycles/{cycle}/tenders', [TenderController::class, 'store'])->middleware('throttle:bid');

        Route::get('/wishis/{wishi}/audit-logs', [AuditLogController::class, 'index']);

        // Platform admin
        Route::middleware('platform.admin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'index']);
            Route::get('/users', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'index']);
            Route::post('/users', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'store'])->middleware('throttle:sensitive');
            Route::get('/users/{id}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'show']);
            Route::put('/users/{id}/toggle-admin', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'toggleAdmin'])->middleware('throttle:sensitive');
            Route::put('/users/{id}/lock', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'lock'])->middleware('throttle:sensitive');
            Route::put('/users/{id}/unlock', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'unlock'])->middleware('throttle:sensitive');
            Route::delete('/users/{id}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'destroy'])->middleware('throttle:sensitive');
            Route::post('/users/{id}/restore', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'restore'])->middleware('throttle:sensitive');
            Route::put('/users/{id}/credit-score', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'adjustCredit'])->middleware('throttle:sensitive');
        });
    });
});
