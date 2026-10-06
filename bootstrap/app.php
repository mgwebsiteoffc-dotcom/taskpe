<?php

use App\Http\Middleware\VerifyShopifySessionToken;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shared hosting usually sits behind a proxy/LiteSpeed — honour
        // X-Forwarded-* so URL generation and client IPs are correct.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'shopify.token'   => VerifyShopifySessionToken::class, // App Bridge JWT for /api/*
            'shopify.webhook' => VerifyShopifyWebhook::class,      // HMAC for /webhooks/*
        ]);

        // Shopify webhooks AND courier NDR pushes are server-to-server POSTs
        // — they cannot carry CSRF tokens. Their own auth (HMAC / signed URL
        // token) protects them instead. Without this, production POSTs 419.
        // Staff portal uses a SameSite=Lax httpOnly cookie — cross-site
        // POSTs never carry it, which is the CSRF defence.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'staff/*',
        ]);

        // IMPORTANT (Shopify embedding): do NOT send X-Frame-Options/CSP that
        // would block framing by admin.shopify.com. Laravel does not add
        // these headers by default — do not add them later.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API consumers (the embedded SPA) should always receive JSON errors.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
