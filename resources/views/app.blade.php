<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- App Bridge v4: the ONLY allowed source is Shopify's CDN, and the api
         key meta tag is its one required input. Nothing else belongs in the
         head: App Bridge reads ?shop= / ?host= from the embedded URL. Adding a
         hand-rolled shop/host meta tag is a common copy-paste that silently
         overrides the real admin context.

         v4 also means the section menu must be declared as <s-app-nav> with
         <s-link> children (mounted by public/js/app.js). The retired
         <ui-nav-menu>/<a> pair from v3 is ignored by this script, which looks
         like "the app menu never appears" rather than like an error. -->
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    <title>{{ config('app.name') }} — Team tasks</title>
    {{-- Cache-busted by file mtime: an FTP upload must never leave a merchant running
         last week's app.js — the admin app menu, the board layout and the fetch layer
         all live in that one file, so "deployed but nothing changed" and "the code is
         wrong" used to look identical. One version string covers both assets. --}}
    @php $taskpeVer = (string) (max((int) @filemtime(public_path('js/app.js')), (int) @filemtime(public_path('css/app.css'))) ?: 1); @endphp
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $taskpeVer }}">
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
                <div class="boot-card">
                    <div class="boot-row">
                        <div class="boot-logo">{{ mb_substr(config('app.name'), 0, 1) }}</div>
                        <div>
                            <div class="boot-name">{{ config('app.name') }}</div>
                            <div class="boot-text">{{ $embedded ? 'Loading your board — columns, tasks and settings.' : 'Waiting for your Shopify session…' }}</div>
                            @if ($shop)<div class="boot-who">{{ $shop }}</div>@endif
                        </div>
                    </div>
                    <div class="skcols">
                        @foreach ([3, 2, 2, 1] as $cards)
                            <div class="skcol">
                                <span class="skbar"></span>
                                @for ($i = 0; $i < $cards; $i++)
                                    <span class="skcard"><span class="skline w80"></span><span class="skline w55"></span></span>
                                @endfor
                            </div>
                        @endforeach
                    </div>
                    {{-- CSS reveals this after ~8s only. A first embedded load can take a
                         few seconds legitimately, so do not hint at a problem earlier. --}}
                    <div class="skslow">
                        <span>Still waiting? Your session with Shopify may have expired.</span>
                        <a class="btn sm" href="{{ request()->fullUrl() }}">Reload</a>
                    </div>
                </div>
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
            // Shopify's admin session context, as this page was opened with it. The SPA routes with
            // pushState and loses the query string, so a link out of the frame (to the plan page)
            // has to carry `host` from here rather than from location.search — without it the admin
            // answers an /admin deep link by opening its own Apps list.
            host: @json($host),
            embedded: @json($embedded),
            billingFlag: new URLSearchParams(location.search).get('billing') || null,
            openTask: new URLSearchParams(location.search).get('task') || null,
            // Which section this path/param asks for. The app menu in Shopify's
            // sidebar (<s-app-nav>, mounted by app.js) is what moves between
            // them — there is deliberately no in-app tab strip.
            view: @json($section ?: null),   // null = open the app, let the SPA choose by role
        };
    </script>
    <script src="{{ asset('js/app.js') }}?v={{ $taskpeVer }}" defer></script>
    @endunless
</body>
</html>
