<?php

use App\Http\Controllers\Api\BillingApiController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ColumnController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ResourceSearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Every route here requires a valid App Bridge session token (JWT).
| The middleware resolves the installed shop into ShopContext; all queries
| are tenant-scoped from that point on. No cookies, no CSRF surface.
|--------------------------------------------------------------------------
*/
Route::middleware('shopify.token')->group(function () {

    Route::get('/board', [BoardController::class, 'show']);

    Route::post('/columns', [ColumnController::class, 'store']);
    Route::patch('/columns/{id}', [ColumnController::class, 'update']);
    Route::delete('/columns/{id}', [ColumnController::class, 'destroy']);

    Route::post('/tasks', [TaskController::class, 'store']);
    // Bulk: one template × many selected Shopify objects (Admin list selection).
    Route::post('/tasks/bulk', [TaskController::class, 'bulk']);
    Route::patch('/tasks/{id}', [TaskController::class, 'update']);
    Route::post('/tasks/{id}/move', [TaskController::class, 'move']);
    Route::post('/tasks/{id}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{id}/remind', [TaskController::class, 'remind']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
    Route::get('/tasks/{id}/activity', [TaskController::class, 'activity']);

    Route::post('/members', [MemberController::class, 'store']);
    Route::patch('/members/{id}', [MemberController::class, 'update']);
    Route::delete('/members/{id}', [MemberController::class, 'destroy']);
    Route::post('/members/{id}/send-otp', [MemberController::class, 'sendOtp']);
    Route::post('/members/{id}/verify', [MemberController::class, 'verify']);
    // Staff portal (web) personal links — mint/rotate or revoke per member.
    Route::post('/members/{id}/portal-link', [MemberController::class, 'portalLink']);
    Route::delete('/members/{id}/portal-link', [MemberController::class, 'revokePortalLink']);
    Route::post('/members/{id}/test', [MemberController::class, 'sendTest']);

    // Teams are the department names columns are tagged with (see TeamController).
    // The name rides in the URL, so it is constrained to one path segment — a team
    // called "a/b" would otherwise reach a route that is not its own.
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::patch('/teams/{name}', [TeamController::class, 'update'])->where('name', '[^/]+');
    Route::delete('/teams/{name}', [TeamController::class, 'destroy'])->where('name', '[^/]+');

    Route::get('/settings', [SettingController::class, 'show']);
    Route::put('/settings', [SettingController::class, 'update']);

    // First-run onboarding completion flag (per shop, shared by all staff).
    Route::post('/onboarding/complete', [SettingController::class, 'completeOnboarding']);

    Route::get('/resources/search', [ResourceSearchController::class, 'search']);

    // For the Admin extensions: which templates fit this page, and what is
    // already on the board for the order/product/customer being viewed.
    Route::get('/task-templates', [TaskController::class, 'templates']);
    Route::get('/resource-tasks', [TaskController::class, 'resourceTasks']);

    Route::get('/digest/preview', [DigestController::class, 'preview']);
    Route::post('/digest/send', [DigestController::class, 'send']);

    // Throttled because both can spend money: 12 clicks a minute is more than a human deciding
    // to upgrade needs, and it bounds a stuck button from creating replacement charges on a loop.
    // Safe in this app only because NoLaravelUser answers `throttle:`'s `$request->user()` first.
    Route::post('/billing/subscribe', [BillingApiController::class, 'subscribe'])
        ->middleware('throttle:12,1');
    Route::post('/billing/cancel', [BillingApiController::class, 'cancel'])
        ->middleware('throttle:12,1');
    Route::post('/billing/sync', [BillingApiController::class, 'sync']);
    // One call per Plan tab, only when the handle behind the plan link is unconfirmed: asks Shopify
    // for its own app handle so the next click lands on the approval page, not on the Apps list.
    Route::post('/billing/verify-handle', [BillingApiController::class, 'verifyHandle'])
        ->middleware('throttle:6,1');
});
