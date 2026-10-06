<?php

use App\Http\Controllers\Api\BillingApiController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ColumnController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ResourceSearchController;
use App\Http\Controllers\Api\SettingController;
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

    Route::get('/settings', [SettingController::class, 'show']);
    Route::put('/settings', [SettingController::class, 'update']);

    // First-run onboarding completion flag (per shop, shared by all staff).
    Route::post('/onboarding/complete', [SettingController::class, 'completeOnboarding']);

    Route::get('/resources/search', [ResourceSearchController::class, 'search']);

    Route::get('/digest/preview', [DigestController::class, 'preview']);
    Route::post('/digest/send', [DigestController::class, 'send']);

    Route::post('/billing/subscribe', [BillingApiController::class, 'subscribe']);
    Route::post('/billing/cancel', [BillingApiController::class, 'cancel']);
    Route::post('/billing/sync', [BillingApiController::class, 'sync']);
});
