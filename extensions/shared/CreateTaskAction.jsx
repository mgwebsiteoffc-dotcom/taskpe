/* @jsxRuntime classic */
/** @jsx h */
/** @jsxFrag Fragment */
import { render, h, Fragment } from "preact";
import { useEffect, useState } from "preact/hooks";

/* ==========================================================================
   TaskPe — "Create task" Admin Action (shared logic)
   Used by the 4 thin target extensions:
     taskpe-task-order         → admin.order-details.action.render
     taskpe-task-draft-order   → admin.draft-order-details.action.render
     taskpe-task-product       → admin.product-details.action.render
     taskpe-task-customer      → admin.customer-details.action.render

   REQUIRED BEFORE DEPLOY: set APP_URL in shared/api.js to your production app
   domain (the same value as APP_URL in your Laravel .env), then
   `shopify app deploy`. Auth + the GID→resource-type map live in that file too,
   so the modal, the order-page card and the bulk action can never disagree.
   ========================================================================== */

import {
  api,
  appUrlNotSet as urlIsPlaceholder,
  firstResource,
  toast,
} from "./api.js";

export function createCreateTaskExtension(resourceLabel) {
  return function extension() {
    render(<CreateTaskAction resourceLabel={resourceLabel} />, document.body);
  };
}

function CreateTaskAction({ resourceLabel }) {
  const { close, i18n } = shopify;

  const { gid, numericId, type: resourceType } = firstResource(shopify);
  const appUrlNotSet = urlIsPlaceholder();

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

    // Two independent calls, on purpose. The board is what this form needs in order to
    // file the task; the search only buys a nicer "Linked: Order #1042" line. Awaiting
    // both together meant one failed lookup sank the other: the search threw, `board`
    // stayed null, the POST went out without a column, and the merchant read "The column
    // id field is required." on a store that has columns. A missing title is survivable
    // the same way now — /api/tasks looks the order number up itself.
    api("/board")
      .then((d) => {
        if (alive) setBoard(d);
      })
      .catch((e) => {
        if (alive) setError(e.message || "Could not reach TaskPe. Is the app installed on this store?");
      });

    if (resourceType) {
      api(`/resources/search?type=${resourceType}&id=${encodeURIComponent(numericId)}`)
        .then((d) => {
          if (alive) setResource(d?.items?.[0] || null);
        })
        .catch(() => {});
    }

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
      toast(shopify, "Task added to TaskPe");
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
          This bundle has no backend address. Set `APP_URL` in
          extensions/shared/api.js to the same https:// domain as APP_URL in the
          Laravel .env, then run shopify app deploy again.
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
        {resource?.title
          ? `Linked: ${resource.title}`
          : `Linked to ${resourceLabel}${numericId ? ` #${numericId}` : ""}.`}
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
