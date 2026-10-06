<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- App Bridge v4: the ONLY allowed source is Shopify's CDN, and the api
         key meta tag is its one required input. Nothing else belongs in the
         head: App Bridge reads ?shop= / ?host= from the embedded URL. Adding a
         hand-rolled shop/host meta tag is a common copy-paste that silently
         overrides the real admin context. -->
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    <title>{{ config('app.name') }} — Team tasks</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main id="root">
        @if ($missingConfig)
            {{-- Loud and server-side on purpose: without these the SPA can only
                 ever answer "something went wrong", which is what hid the real
                 problem for every deploy so far. --}}
            <div class="gate">
                <div class="panel gate-card">
                    <div class="p-head">
                        <div class="brand-badge" style="background:var(--critical)">!</div>
                        <h2>TaskPe is not configured on this server yet</h2>
                    </div>
                    <div class="p-body">
                        <p class="muted small">Missing / unusable values in <code>.env</code> — set them,
                            run <code>php artisan config:clear</code>, then reload:</p>
                        <ul class="gate-steps">
                            @foreach ($missingConfig as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @else
            <div class="boot">
                <div class="boot-logo">{{ mb_substr(config('app.name'), 0, 1) }}</div>
                <div class="boot-text">{{ $embedded ? 'Loading your board…' : 'Waiting for your Shopify session…' }}</div>
            </div>
            <noscript>
                <div class="gate">
                    <div class="panel gate-card"><div class="p-body">
                        TaskPe is a JavaScript app that runs inside Shopify Admin
                        (Apps → TaskPe). Please enable JavaScript, or use the
                        staff board at <a href="/staff">/staff</a>.
                    </div></div>
                </div>
            </noscript>
        @endif
    </main>

    @unless ($missingConfig)
    <!-- Server-injected bootstrap (never secrets) -->
    <script>
        window.__TASKPE__ = {
            // apiBase() in app.js keeps XHRs same-origin; this is only the
            // value merchants/Shopify see (and what OAuth redirect_uri uses).
            appUrl: @json($appUrl),
            shop: @json($shop),
            embedded: @json($embedded),
            billingFlag: new URLSearchParams(location.search).get('billing') || null,
            openTask: new URLSearchParams(location.search).get('task') || null,
            // Which section this path/param asks for. The app menu in Shopify's
            // sidebar (ui-nav-menu, mounted by app.js) is what moves between
            // them — there is deliberately no in-app tab strip.
            view: @json($section ?? 'board'),
        };
    </script>
    <script src="{{ asset('js/app.js') }}" defer></script>
    @endunless
</body>
</html>
