<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Embedded app shell (serves the SPA; App Bridge + session tokens take over)
Route::get('/', [AppController::class, 'index'])->name('app');
Route::get('/privacy', [AppController::class, 'privacy'])->name('privacy');

// OAuth (top-level navigation, outside the iframe)
Route::get('/auth/shopify', [AuthController::class, 'redirect'])->name('shopify.auth');
Route::get('/auth/shopify/callback', [AuthController::class, 'callback'])->name('shopify.auth.callback');

// Shopify Billing hosted-page return
Route::get('/billing/callback', [BillingController::class, 'callback'])->name('billing.callback');

// ALL webhooks (app/uninstalled + GDPR mandatory topics), HMAC-verified
Route::post('/webhooks/shopify', [WebhookController::class, 'handle'])
    ->middleware('shopify.webhook')
    ->name('webhooks.shopify');

// Courier NDR intake (Shiprocket/Delhivery/XpressBees push URL — token auth)
Route::post('/webhooks/ndr/{token}', [\App\Http\Controllers\NdrIntakeController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('webhooks.ndr.intake');

/*
|--------------------------------------------------------------------------
| Staff web portal — the full board for teammates WITHOUT Shopify admin
| access (Shopify Basic = one staff seat). Auth: personal invite link or
| WhatsApp OTP → signed cookie. Tenant scoping identical to the admin API.
|--------------------------------------------------------------------------
*/
Route::get('/staff', [\App\Http\Controllers\StaffPortalController::class, 'index'])->name('staff.portal');
Route::post('/staff/login', [\App\Http\Controllers\StaffLoginController::class, 'send'])->middleware('throttle:5,1')->name('staff.login');
Route::post('/staff/verify', [\App\Http\Controllers\StaffLoginController::class, 'verify'])->middleware('throttle:10,1')->name('staff.verify');
Route::post('/staff/logout', [\App\Http\Controllers\StaffLoginController::class, 'logout'])->name('staff.logout');
Route::get('/staff/invite/{token}', [\App\Http\Controllers\StaffInviteController::class, 'accept'])->name('staff.invite');

Route::prefix('staff/api')->middleware(\App\Http\Middleware\StaffPortalAuth::class)->group(function () {
    Route::get('/board', [\App\Http\Controllers\StaffBoardController::class, 'show']);
    Route::get('/resources/search', [\App\Http\Controllers\Api\ResourceSearchController::class, 'search']);
    Route::post('/tasks', [\App\Http\Controllers\StaffTaskController::class, 'store']);
    Route::patch('/tasks/{id}', [\App\Http\Controllers\StaffTaskController::class, 'update']);
    Route::post('/tasks/{id}/move', [\App\Http\Controllers\StaffTaskController::class, 'move']);
    Route::post('/tasks/{id}/complete', [\App\Http\Controllers\StaffTaskController::class, 'complete']);
    Route::get('/tasks/{id}/activity', [\App\Http\Controllers\StaffTaskController::class, 'activity']);
    // Deliberately absent for staff: task delete, WhatsApp reminder,
    // columns CRUD, members CRUD, settings, billing.
});
