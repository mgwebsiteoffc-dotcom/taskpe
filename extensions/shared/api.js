/* ==========================================================================
   TaskPe — shared plumbing for EVERY Admin extension (action, block, bulk)
   --------------------------------------------------------------------------
   One place for: where the backend is, how we authenticate to it, and how a
   Shopify GID becomes a TaskPe resource link. If you rename your domain, this
   is the only file to edit — the modal, the order-page card and the bulk
   action all import from here.

   REQUIRED BEFORE DEPLOY: set APP_URL to your production app domain (the same
   value as APP_URL in your Laravel .env), then run `shopify app deploy`.
   ========================================================================== */

export const APP_URL = "https://app.yourdomain.com"; // ← CHANGE ME before deploy

/** gid://shopify/<Node>/<id> → the `resource_type` Task stores. */
export const TYPE_MAP = {
  Order: "order",
  DraftOrder: "draft_order",
  Product: "product",
  Customer: "customer",
  Article: "article",
};

/** True while the placeholder is still in place — every UI shows a banner. */
export function appUrlNotSet() {
  return !APP_URL || APP_URL.includes("yourdomain");
}

/**
 * Session-token JWT from the extension runtime (App Bridge v4 style auth).
 * `shopify.auth.idToken` is the documented 2026 path; older runtimes exposed
 * `shopify.idToken`, so try both before blaming the merchant.
 */
export async function sessionToken() {
  const s = globalThis.shopify;
  if (s?.auth?.idToken) return s.auth.idToken();
  if (s?.idToken) return s.idToken();
  throw new Error("Shopify session token unavailable in this context");
}

/**
 * Call the TaskPe Laravel API. Same middleware as the embedded SPA
 * (VerifyShopifySessionToken), so a task created here behaves identically.
 */
export async function api(path, options = {}) {
  const { method = "GET", body } = options;
  const res = await fetch(`${APP_URL}/api${path}`, {
    method,
    headers: {
      Authorization: `Bearer ${await sessionToken()}`,
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || data.error || `Request failed (${res.status})`);
  }
  return data;
}

/**
 * The resource(s) the merchant is looking at / has ticked.
 *
 * Actions and blocks both hand us `shopify.data.selected`, but block runtimes
 * have shipped shapes where `shopify.data` *is* the array, and bulk targets
 * hand us many ids — so normalise all three instead of picking one and
 * breaking on the next admin change.
 */
export function selectedResources(shopify) {
  const raw = shopify?.data?.selected ?? shopify?.data ?? [];
  const list = Array.isArray(raw) ? raw : [raw];

  return list
    .filter((item) => item && item.id)
    .map((item) => {
      const gid = String(item.id);
      const parts = gid.split("/");
      return {
        gid,
        numericId: parts[parts.length - 1] || "",
        type: TYPE_MAP[parts[3]] || null,
        // Some targets hand over a rich object; take the title if it is there
        // (saves a backend round-trip when it is).
        title: item.title || item.name || null,
      };
    });
}

export function firstResource(shopify) {
  return selectedResources(shopify)[0] || { gid: "", numericId: "", type: null, title: null };
}

export function toast(shopify, message) {
  try {
    shopify?.toast?.show?.(message);
  } catch (e) {
    /* toasts are best-effort — never fail a write because of one */
  }
}

/**
 * Jump out to a full admin page (our app, or a specific task on the board).
 * `shopify.navigation` is the sanctioned way from a block; window.open is the
 * fallback because a sandboxed iframe may simply swallow it.
 */
export function openLink(shopify, url) {
  if (!url) return;
  try {
    if (shopify?.navigation?.url) {
      shopify.navigation.url(url);
      return;
    }
    if (typeof shopify?.navigation?.navigateTo === "function") {
      shopify.navigation.navigateTo({ url });
      return;
    }
  } catch (e) {
    /* fall through to window.open */
  }
  try {
    window.open(url, "_blank", "noopener");
  } catch (e) {
    /* nothing else we can do from inside the sandbox */
  }
}
