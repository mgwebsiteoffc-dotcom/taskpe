<?php

namespace App\Providers;

use App\Support\ShopContext;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant holder per request — set by the session-token
        // middleware, read by every controller.
        $this->app->singleton(ShopContext::class);
    }

    public function boot(): void
    {
        // Shared hosts terminate TLS at the proxy — make sure generated
        // URLs (OAuth redirect_uri, webhook callback) are always https.
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
