<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }} — Privacy Policy</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.6;color:#1a1a1a}
        h1{font-size:26px} h2{font-size:18px;margin-top:28px}
        li{margin:4px 0} .muted{color:#666}
    </style>
</head>
<body>
    <h1>Privacy Policy — {{ $appName }}</h1>
    <p class="muted">Last updated: September 2026</p>

    <h2>What we collect</h2>
    <ul>
        <li><b>Shop information:</b> your myshopify.com domain, shop name, timezone and an API access token required to run the app.</li>
        <li><b>Team members you add:</b> name and WhatsApp phone number of your staff, used solely to assign tasks and send them WhatsApp notifications through your own Whatify account.</li>
        <li><b>Tasks you create:</b> titles, descriptions, due dates, and links to your Shopify objects (order / draft order / product / customer / blog article) as an ID + display label + admin URL.</li>
        <li><b>Your Whatify API key</b> (encrypted at rest), used only to send messages on your behalf.</li>
    </ul>

    <h2>What we do NOT collect</h2>
    <p>We never store your customers' personal data (no emails, addresses or phone numbers from orders). We never sell data, run no third-party analytics or trackers, and never message your customers.</p>

    <h2>Data deletion</h2>
    <ul>
        <li>When you uninstall, your access token and Whatify API key are deleted immediately and operational data is marked for removal.</li>
        <li>On Shopify's <code>shop/redact</code> request (48h after uninstall) all shop data is permanently deleted automatically.</li>
        <li>We honour Shopify's mandatory GDPR webhooks (<code>customers/data_request</code>, <code>customers/redact</code>, <code>shop/redact</code>). Because we hold no customer PII, customer redaction requests result in a confirmed "no data held" response.</li>
    </ul>

    <h2>WhatsApp messages</h2>
    <p>WhatsApp notifications are sent through <b>your own</b> Whatify/WhatsApp Business account to staff numbers you explicitly add and verify by OTP. Message delivery logs (recipient, status, timestamp) are kept for 90 days for debugging, then automatically deleted.</p>

    <h2>Contact</h2>
    <p>For privacy questions or deletion requests, email the address listed on our Shopify App Store listing.</p>
</body>
</html>
