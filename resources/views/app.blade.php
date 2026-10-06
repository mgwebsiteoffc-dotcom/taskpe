<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- App Bridge v4: the ONLY allowed source is Shopify's CDN. The
         api key meta tag configures it automatically. -->
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    <title>{{ config('app.name') }} — Team tasks</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main id="root">
        <div class="boot">
            <div class="boot-logo">{{ mb_substr(config('app.name'), 0, 1) }}</div>
            <div class="boot-text">Loading your board…</div>
        </div>
    </main>

    <!-- Server-injected bootstrap (never secrets) -->
    <script>
        window.__TASKPE__ = {
            appUrl: @json($appUrl),
            billingFlag: new URLSearchParams(location.search).get('billing') || null,
            openTask: new URLSearchParams(location.search).get('task') || null,
        };
    </script>
    <script src="{{ asset('js/app.js') }}" defer></script>
</body>
</html>
