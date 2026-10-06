import { render } from "preact";
import { useEffect, useState } from "preact/hooks";

/* ==========================================================================
   TaskPe — "Create task" Admin Action (shared logic)
   Used by the 4 thin target extensions:
     taskpe-task-order         → admin.order-details.action.render
     taskpe-task-draft-order   → admin.draft-order-details.action.render
     taskpe-task-product       → admin.product-details.action.render
     taskpe-task-customer      → admin.customer-details.action.render

   REQUIRED BEFORE DEPLOY: set APP_URL to your production app domain
   (the same value as APP_URL in your Laravel .env), then `shopify app deploy`.

   Auth: the extension gets a Shopify session-token JWT via the Standard API
   (shopify.auth.idToken) and authenticates to this Laravel backend exactly
   like the embedded SPA does — same VerifyShopifySessionToken middleware.
   ========================================================================== */

const APP_URL = "https://app.yourdomain.com"; // ← CHANGE ME before deploy

const TYPE_MAP = {
  Order: "order",
  DraftOrder: "draft_order",
  Product: "product",
  Customer: "customer",
};

async function sessionToken() {
  const s = globalThis.shopify;
  if (s?.auth?.idToken) return s.auth.idToken();
  if (s?.idToken) return s.idToken(); // older runtimes
  throw new Error("Shopify session token unavailable in this context");
}

async function api(path, options = {}) {
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

export function createCreateTaskExtension(resourceLabel) {
  return function extension() {
    render(<CreateTaskAction resourceLabel={resourceLabel} />, document.body);
  };
}

function CreateTaskAction({ resourceLabel }) {
  const { close, data, i18n } = shopify;

  const gid = data?.selected?.[0]?.id || "";
  const numericId = gid.split("/").pop() || "";
  const typename = gid.split("/")[3] || "";
  const resourceType = TYPE_MAP[typename] || null;
  const appUrlNotSet = APP_URL.includes("yourdomain");

  const [form, setForm] = useState({
    title: "",
    description: "",
    assigneeId: "",
    priority: "medium",
    dueDate: "",
  });
  const [board, setBoard] = useState(null);
  const [resource, setResource] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (appUrlNotSet) return;
    let alive = true;
    (async () => {
      try {
        const [boardData, resourceData] = await Promise.all([
          api("/board"),
          resourceType
            ? api(`/resources/search?type=${resourceType}&id=${encodeURIComponent(numericId)}`)
            : Promise.resolve({ items: [] }),
        ]);
        if (!alive) return;
        setBoard(boardData);
        setResource(resourceData?.items?.[0] || null);
      } catch (e) {
        if (alive) setError(e.message || "Could not reach TaskPe. Is the app installed on this store?");
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  const onSubmit = async () => {
    if (!form.title.trim()) {
      setError("Please enter a task title.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await api("/tasks", {
        method: "POST",
        body: {
          title: form.title.trim(),
          description: form.description.trim() || null,
          priority: form.priority,
          due_at: form.dueDate ? `${form.dueDate}T10:00:00Z` : null,
          assignee_id: form.assigneeId ? Number(form.assigneeId) : null,
          column_id: board?.columns?.[0]?.id,
          resource_type: resourceType,
          resource_id: numericId ? Number(numericId) : null,
          resource_gid: gid || null,
          resource_title: resource?.title || null,
          resource_url: resource?.url || null,
        },
      });
      try {
        shopify?.toast?.show?.("Task added to TaskPe");
      } catch (e) {
        /* toast is best-effort */
      }
      close();
    } catch (e) {
      setError(e.message || "Could not create the task.");
    } finally {
      setBusy(false);
    }
  };

  if (appUrlNotSet) {
    return (
      <s-admin-action heading="Create task">
        <s-banner tone="critical">
          APP_URL is not configured. Edit extensions/shared/CreateTaskAction.jsx,
          set your live domain, then run shopify app deploy again.
        </s-banner>
      </s-admin-action>
    );
  }

  return (
    <s-admin-action heading={i18n.translate("name")}>
      <s-button slot="primary-action" onClick={onSubmit} loading={busy} disabled={busy}>
        {i18n.translate("create")}
      </s-button>
      <s-button slot="secondary-actions" onClick={close} disabled={busy}>
        {i18n.translate("cancel")}
      </s-button>

      {error ? <s-banner tone="critical">{error}</s-banner> : null}

      <s-text appearance="subdued">
        Linked: {resourceLabel}
        {resource?.title ? ` — ${resource.title}` : ""}
      </s-text>

      <s-box padding-block-start="large">
        <s-text-field
          label={i18n.translate("title-label")}
          value={form.title}
          placeholder="e.g. Refund this customer and send ₹500 gift card"
          onChange={(event) => setForm((prev) => ({ ...prev, title: event.target.value }))}
          max-length={190}
          required
        />
      </s-box>

      <s-box padding-block-start="large">
        <s-text-area
          label={i18n.translate("description-label")}
          value={form.description}
          onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
          max-length={500}
        />
      </s-box>

      <s-box padding-block-start="large">
        <s-stack direction="inline" gap="large">
          <s-select
            label={i18n.translate("assignee-label")}
            value={form.assigneeId}
            onChange={(event) => setForm((prev) => ({ ...prev, assigneeId: event.target.value }))}
          >
            <s-option value="">Unassigned</s-option>
            {(board?.members || [])
              .filter((m) => m.active)
              .map((m) => (
                <s-option key={m.id} value={String(m.id)}>
                  {m.name}
                </s-option>
              ))}
          </s-select>

          <s-select
            label={i18n.translate("priority-label")}
            value={form.priority}
            onChange={(event) => setForm((prev) => ({ ...prev, priority: event.target.value }))}
          >
            {["low", "medium", "high", "urgent"].map((p) => (
              <s-option key={p} value={p}>
                {p}
              </s-option>
            ))}
          </s-select>

          <s-date-field
            label={i18n.translate("due-label")}
            value={form.dueDate}
            onChange={(event) => setForm((prev) => ({ ...prev, dueDate: event.target.value }))}
          />
        </s-stack>
      </s-box>
    </s-admin-action>
  );
}
