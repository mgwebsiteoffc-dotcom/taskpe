<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — plan change</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:620px;margin:64px auto;padding:0 20px;line-height:1.6;color:#1a1a1a}
        h1{font-size:22px} .card{border:1px solid #e3e3e3;border-radius:10px;padding:18px 20px;background:#fafafa}
        .muted{color:#666} code{background:#f1f1f1;padding:1px 5px;border-radius:4px}
        a{color:#0f6e4d}
    </style>
</head>
<body>
    <h1>Your plan change is with Shopify</h1>

    @if ($domain === '')
        <p>We brought you back to <b>{{ config('app.name') }}</b> after Shopify's plan page, but this
            request carried no store name, so this page cannot tell your board anything. Nothing is wrong
            with the plan you picked — Shopify records that on your invoice either way.</p>
    @else
        <p>We brought you back to <b>{{ config('app.name') }}</b> after Shopify's plan page, but the store
            <code>{{ $domain }}</code> could not be matched to an install on this server. Nothing is wrong with
            the plan you picked — Shopify records that on your invoice either way.</p>
    @endif

    <div class="card">
        <p style="margin-top:0"><b>What to do:</b> open {{ config('app.name') }} from <b>Apps</b> in your
            Shopify admin. The Plan tab reads your current plan straight from Shopify, so it will already
            show the plan you chose — there is nothing to confirm here, and no data changed in the meantime.</p>
        <p class="muted" style="margin-bottom:0">If the Plan tab still shows the old plan a minute later, use
            <b>Check again</b> there. It asks Shopify directly rather than trusting this redirect.</p>
    </div>

    <p class="muted small">This page exists because a plan change should never end on a technical message.
        Plans, prices, trials and cancellations for this app live in your Shopify account, not in the app.</p>
</body>
</html>
