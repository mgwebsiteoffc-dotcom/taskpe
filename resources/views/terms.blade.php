<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }} — Terms of Service</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.6;color:#1a1a1a}
        h1{font-size:26px} h2{font-size:18px;margin-top:28px}
        li{margin:4px 0} .muted{color:#666} .note{background:#fff8e6;border:1px solid #f0dfa8;padding:10px 14px;border-radius:6px;font-size:14px}
    </style>
</head>
<body>
    <h1>Terms of Service — {{ $appName }}</h1>
    <p class="muted">Last updated: October 2026</p>

    <div class="note">
        Two names still have to go in here before this page is quoted anywhere: the legal entity
        that runs the app and the governing law. Search for <b>[</b> on this page, replace both, then
        remove this box.
    </div>

    <h2>1. What this service is</h2>
    <p>{{ $appName }} is an internal task board for Shopify merchants, used inside the Shopify admin and on a
        phone. It reads a merchant's orders, products and content so a task can be linked to the Shopify object it
        is about, and it reminds staff about open tasks. It is not a fulfilment, shipping, accounting or customer
        service product, and it never contacts a merchant's customers.</p>

    <h2>2. Your account and your data</h2>
    <ul>
        <li>You install the app on your own store and you decide what it is allowed to do — the automations and the
            WhatsApp alerts are off until you switch them on in Settings.</li>
        <li>You are responsible for the task content you and your team enter, and for having the right to store it.</li>
        <li>Team members you invite can see and complete the tasks in your store's board. They sign in with a code
            sent to the phone number you added.</li>
        <li>Your Shopify store, its data and your relationship with Shopify stay governed by
            <a href="https://www.shopify.com/legal/tos">Shopify's own Terms of Service</a>.</li>
    </ul>

    <h2>3. Fees</h2>
    <ul>
        <li>Plan fees are billed <b>through Shopify</b>, on your Shopify invoice, in your store's billing currency.
            Cancelling a plan happens on Shopify's subscription page, not here.</li>
        <li>WhatsApp messages are billed by <b>your own</b> messaging provider (Whatify), per message. {{ $appName }}
            charges nothing for messages and does not resell them; a key for your account is what the app uses.</li>
        <li>Any taxes shown on your Shopify invoice are handled by Shopify where it is required to collect them.</li>
    </ul>

    <h2>4. Acceptable use</h2>
    <p>Do not use the app to store sensitive personal data about other people, to send messages to people who did not
        opt in, to circumvent Shopify's API limits or access scopes, or to do anything unlawful. We may suspend access
        to protect Shopify's platform, our other merchants, or ourselves — with notice where it is possible to give it.</p>

    <h2>5. Availability, changes and no warranty</h2>
    <p>The app is provided "as is", without a warranty of any kind, express or implied. It depends on the Shopify Admin
        API and on your store's settings: when Shopify changes or revokes an access scope, a permission or an API
        version, behaviour can change with it, and some features stop until the store re-approves them. We do not
        guarantee uninterrupted operation, and we do not guarantee outcomes — a task completed here does not by itself
        make a delivery succeed or money arrive.</p>
    <p>We may change these terms as the product or the platform changes. Material changes are announced in the app and
        on this page; keeping the app installed afterwards means the new terms apply.</p>

    <h2>6. Liability</h2>
    <p>To the maximum extent the law allows, we are not liable for indirect, incidental or consequential loss, and our
        total liability for anything arising from the app is limited to the amount you paid for it in the twelve
        months before the claim. Nothing here limits liability that cannot be limited by law.</p>

    <h2>7. Ending the agreement, and your data</h2>
    <p>You can stop any time by uninstalling the app. On uninstall we delete the access token and your messaging key
        immediately; everything else about your store is deleted on Shopify's <code>shop/redact</code> request, which
        follows 48 hours later, and the scheduled jobs prune message logs and the webhook ledger. See the
        <a href="/privacy">privacy policy</a> for what is held and for how long.</p>

    <h2>8. Governing law and contact</h2>
    <p>These terms are governed by the laws of <b>[COUNTRY / STATE]</b>, and disputes go to the courts there. For
        anything in these terms, write to the address on our Shopify App Store listing, or in an emergency to the
        developer contact registered with Shopify.</p>
    <p class="muted">Operated by <b>[YOUR COMPANY NAME]</b>.</p>
</body>
</html>
