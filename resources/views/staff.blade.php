<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#008060">
    <title>{{ config('app.name') }} — Staff board</title>
@php
    {{-- Cache-busted by file mtime, max of the two assets. An FTP upload must never
         leave a merchant running last week's app.js: the admin app menu, the board
         layout and the fetch layer all live in that one file, and "I deployed it but
         nothing changed" is otherwise indistinguishable from "the code is wrong". --}}
    $taskpeVer = (string) (max(@filemtime(public_path('js/app.js')) ?: 0, @filemtime(public_path('css/app.css')) ?: 0)
        ?: config('app.asset_version', '1'));
@endphp
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $taskpeVer }}">
    <style>
        .staff-brand { display:flex; align-items:center; gap:10px; font-weight:700; font-size:18px; }
        .login-wrap { min-height: 100dvh; display:flex; align-items:center; justify-content:center; padding:20px; background:#f6f6f7; }
        .login-card { width:min(400px,100%); }
        .login-card h1 { font-size:20px; margin:0 0 4px; }
        .login-card .sub { color:var(--text-subdued,#6d7175); font-size:13px; margin:0 0 18px; }
        .login-card .panel-body-pad { padding:22px; }
        .login-err { color:var(--critical,#d72c0d); font-size:13px; min-height:18px; margin:4px 0 10px; }
        .login-note { font-size:12px; color:var(--text-subdued,#6d7175); margin-top:14px; }
    </style>
</head>
<body>
@if ($member)
    {{-- Authenticated staff — the same board shell as the admin app. --}}
    <main id="root">
        <div class="boot">
            <div class="boot-logo">{{ mb_substr(config('app.name'), 0, 1) }}</div>
            <div class="boot-text">Loading your board…</div>
        </div>
    </main>
    <script>
        window.__TASKPE__ = {
            appUrl: @json($appUrl),
            staff: {
                id: {{ $member->id }},
                name: @json($member->name),
                initials: @json($member->initials()),
                shopName: @json($member->shop->name ?? ''),
            },
        };
    </script>
    <script src="{{ asset('js/app.js') }}?v={{ $taskpeVer }}" defer></script>
@else
    {{-- Signed-out: phone + WhatsApp OTP, or manager-sent invite link. --}}
    <div class="login-wrap">
        <div class="panel login-card">
            <div class="panel-body-pad">
                <div class="staff-brand">
                    <span class="boot-logo" style="width:36px;height:36px;font-size:17px;">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                    {{ config('app.name') }} — staff sign in
                </div>
                <p class="sub">For teammates working on the shared task board.</p>

                <div id="staffLogin">
                    <div class="field">
                        <label>Your WhatsApp number</label>
                        <input class="input" id="sl-phone" inputmode="tel" placeholder="98765 43210" autocomplete="tel">
                    </div>
                    <div class="login-err" id="sl-err"></div>
                    <button class="btn primary" id="sl-send" style="width:100%">Send sign-in code</button>

                    <div id="sl-otp" style="display:none;margin-top:16px">
                        <div class="field">
                            <label>6-digit code from WhatsApp</label>
                            <input class="input" id="sl-code" inputmode="numeric" maxlength="6" placeholder="••••••" style="letter-spacing:6px;text-align:center;font-size:18px">
                        </div>
                        <button class="btn primary" id="sl-verify" style="width:100%">Sign in</button>
                    </div>

                    <p class="login-note">
                        No code arriving? Your store manager can send you a personal
                        <b>staff portal link</b> instead — Team tab → Portal link.
                        (WhatsApp sign-in works when your store has WhatsApp alerts enabled
                        and your number verified.)
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        // Same-origin relative on purpose: the sign-in endpoints belong to this
        // very app, so a stale APP_URL (or a proxied Host header) can never send
        // a staff login POST somewhere else.
        const $ = id => document.getElementById(id);
        const err = t => { $('sl-err').textContent = t || ''; };

        async function post(path, body) {
            const res = await fetch(path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'Something went wrong.');
            return data;
        }

        $('sl-send').addEventListener('click', async function () {
            this.disabled = true; err('');
            try {
                await post('/staff/login', { phone: $('sl-phone').value.trim() });
                $('sl-otp').style.display = 'block';
                $('sl-code').focus();
                this.textContent = 'Code sent — check WhatsApp';
            } catch (e) { err(e.message); this.disabled = false; }
        });

        $('sl-verify').addEventListener('click', async function () {
            this.disabled = true; err('');
            try {
                await post('/staff/verify', { phone: $('sl-phone').value.trim(), code: $('sl-code').value.trim() });
                location.replace('/staff');
            } catch (e) { err(e.message); this.disabled = false; }
        });
    })();
    </script>
@endif
</body>
</html>
