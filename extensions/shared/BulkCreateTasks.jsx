/* @jsxRuntime classic */
/** @jsx h */
/** @jsxFrag Fragment */
import { render, h, Fragment } from "preact";
import { useEffect, useMemo, useState } from "preact/hooks";
import {
  api,
  appUrlNotSet as urlIsPlaceholder,
  selectedResources,
  toast,
} from "./api.js";

/* ==========================================================================
   TaskPe — bulk "Create tasks" on the Orders list
   (admin.order-index.selection-action.render)
   --------------------------------------------------------------------------
   Tick N orders in the list → Orders ··· / bulk bar → "Create TaskPe tasks" →
   pick a template + who does them → N tasks, each pre-linked to its own order
   with the template checklist, priority and due rule already applied.

   Why it is safe to press twice: the API skips any order that already has an
   OPEN task with the same rendered title (POST /api/tasks/bulk), so a re-run
   reports "already on the board" instead of doubling up. The plan's open-task
   ceiling is enforced per created task, so the batch stops at the limit.

   Shopify has no API for a button inside an individual order-list row, so the
   two supported list affordances are this selection action and the More-actions
   entry (taskpe-task-order) — plus the inline card on the order page.
   ========================================================================== */

const MAX_BATCH = 100; // matches the `max:100` rule on resources.*

export function createBulkTaskExtension(defaultTemplate) {
  return function extension() {
    render(<BulkCreateTasks defaultTemplate={defaultTemplate} />, document.body);
  };
}

function tr(i18n, key, fallback) {
  try {
    // A missing key comes back AS the key, so compare before trusting it —
    // otherwise every untranslated string renders "collapsed-empty" to the merchant.
    const t = i18n?.translate ? String(i18n.translate(key) ?? '') : '';
    return t && t !== key ? t : fallback;
  } catch (e) {
    return fallback;
  }
}

function BulkCreateTasks({ defaultTemplate }) {
  const shopify = globalThis.shopify;
  const { close, i18n } = shopify || {};

  const selected = useMemo(() => selectedResources(shopify), []);
  const resourceType = selected[0]?.type || "order";

  const [meta, setMeta] = useState(null);
  const [template, setTemplate] = useState(defaultTemplate || "");
  const [assigneeId, setAssigneeId] = useState("");
  const [columnId, setColumnId] = useState("");
  const [notify, setNotify] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState(null);

  useEffect(() => {
    if (urlIsPlaceholder()) return;
    let alive = true;
    (async () => {
      try {
        const data = await api(`/task-templates?type=${encodeURIComponent(resourceType)}`);
        if (!alive) return;
        setMeta(data);
        setColumnId(data.columns?.[0]?.id ? String(data.columns[0].id) : "");
        if (!template && data.templates?.length) setTemplate(data.templates[0].key);
      } catch (e) {
        if (alive) {
          setError(e.message || tr(i18n, "unreachable", "Could not reach TaskPe. Is the app installed on this store?"));
        }
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  const templates = useMemo(
    () => (meta?.templates || []).filter((t) => !t.resource_type || t.resource_type === resourceType),
    [meta, resourceType],
  );

  const ids = selected.slice(0, MAX_BATCH);
  const chosen = templates.find((t) => t.key === template) || null;

  async function create() {
    if (!template) {
      setError(tr(i18n, "pick-template", "Pick a template first."));
      return;
    }
    setBusy(true);
    setError("");
    try {
      const res = await api("/tasks/bulk", {
        method: "POST",
        body: {
          template,
          assignee_id: assigneeId ? Number(assigneeId) : null,
          column_id: columnId ? Number(columnId) : null,
          notify: notify && !!assigneeId,
          resources: ids.map((r) => ({ type: r.type || resourceType, id: Number(r.numericId), gid: r.gid, title: r.title })),
        },
      });
      setResult(res);
      toast(shopify, `${res.created} task${res.created === 1 ? "" : "s"} created`);
    } catch (e) {
      setError(e.message || tr(i18n, "failed", "Could not create the tasks."));
    } finally {
      setBusy(false);
    }
  }

  if (urlIsPlaceholder()) {
    return (
      <s-admin-action heading="Create TaskPe tasks">
        <s-banner tone="critical">
          APP_URL is not configured. Edit extensions/shared/api.js, set your live
          domain, then run shopify app deploy again.
        </s-banner>
      </s-admin-action>
    );
  }

  if (result) {
    const skipped = (result.skipped || []).length;
    return (
      <s-admin-action heading={tr(i18n, "done-heading", "Done")}>
        <s-button slot="primary-action" onClick={() => close()}>
          {tr(i18n, "close", "Close")}
        </s-button>
        <s-stack gap="base">
          <s-text>{result.message}</s-text>
          {skipped ? (
            <s-text appearance="subdued">
              {skipped} skipped — {tr(i18n, "skipped-hint", "those orders already have an open task with this title.")}
            </s-text>
          ) : null}
          {result.limited ? (
            <s-banner tone="warning">{tr(i18n, "limited", "Stopped at your plan's open-task limit. Upgrade to queue more.")}</s-banner>
          ) : null}
        </s-stack>
      </s-admin-action>
    );
  }

  return (
    <s-admin-action heading={tr(i18n, "name", "Create TaskPe tasks")}>
      <s-button slot="primary-action" onClick={create} loading={busy} disabled={busy || !ids.length}>
        {tr(i18n, "create-n", `Create ${ids.length} task${ids.length === 1 ? "" : "s"}`)}
      </s-button>
      <s-button slot="secondary-actions" onClick={() => close()} disabled={busy}>
        {tr(i18n, "cancel", "Cancel")}
      </s-button>

      {error ? <s-banner tone="critical">{error}</s-banner> : null}
      {meta?.plan?.full ? (
        <s-banner tone="warning">
          {tr(i18n, "plan-full", "You are already at your plan's open-task limit — nothing new can be filed until you upgrade.")}
        </s-banner>
      ) : null}

      <s-text appearance="subdued">
        {ids.length} {tr(i18n, "selected", "selected")} · {resourceType.replace("_", " ")}
        {ids.length > MAX_BATCH ? ` · only the first ${MAX_BATCH} are filed` : ""}
      </s-text>

      <s-box padding-block-start="large">
        <s-stack gap="small">
          <s-text>{tr(i18n, "template-label", "Template")}</s-text>
          {templates.map((tpl) => (
            <s-box padding="base" key={tpl.key}>
              <s-stack direction="inline" gap="base">
                <s-checkbox checked={tpl.key === template} onChange={() => setTemplate(tpl.key)} />
                <s-stack gap="extra-small">
                  <s-text>{tpl.name}</s-text>
                  {tpl.tagline ? <s-text appearance="subdued">{tpl.tagline}</s-text> : null}
                </s-stack>
              </s-stack>
            </s-box>
          ))}
          {!templates.length && !error ? (
            <s-text appearance="subdued">
              {tr(i18n, "no-templates", "No templates fit this page — check config/task_templates.php.")}
            </s-text>
          ) : null}
        </s-stack>
      </s-box>

      {chosen?.steps ? (
        <s-text appearance="subdued">
          {chosen.steps} checklist {chosen.steps === 1 ? "step" : "steps"} come with every task
          {chosen.due_in_hours ? ` · due in ${chosen.due_in_hours}h` : ""}
        </s-text>
      ) : null}

      <s-box padding-block-start="large">
        <s-stack direction="inline" gap="large">
          <s-select
            label={tr(i18n, "assignee-label", "Assign to")}
            value={assigneeId}
            onChange={(event) => setAssigneeId(event.target.value)}
          >
            <s-option value="">{tr(i18n, "unassigned", "Unassigned")}</s-option>
            {(meta?.members || []).map((m) => (
              <s-option key={m.id} value={String(m.id)}>
                {m.name}
              </s-option>
            ))}
          </s-select>

          <s-select
            label={tr(i18n, "column-label", "Board column")}
            value={columnId}
            onChange={(event) => setColumnId(event.target.value)}
          >
            {(meta?.columns || []).map((c) => (
              <s-option key={c.id} value={String(c.id)}>
                {c.name}
              </s-option>
            ))}
          </s-select>
        </s-stack>
      </s-box>

      {assigneeId ? (
        <s-box padding-block-start="base">
          <s-checkbox checked={notify} onChange={(event) => setNotify(event.target.checked)}>
            {tr(i18n, "notify", "Ping the assignee on WhatsApp (max 3 per batch)")}
          </s-checkbox>
        </s-box>
      ) : null}
    </s-admin-action>
  );
}
