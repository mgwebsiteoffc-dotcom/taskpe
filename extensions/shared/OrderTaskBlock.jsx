/* @jsxRuntime classic */
/** @jsx h */
/** @jsxFrag Fragment */
import { render, h, Fragment } from "preact";
import { useEffect, useState } from "preact/hooks";
import {
  api,
  appUrlNotSet as urlIsPlaceholder,
  firstResource,
  openLink,
  toast,
} from "./api.js";

/* ==========================================================================
   TaskPe — order-page card (admin.order-details.block.render)
   --------------------------------------------------------------------------
   Everything the board needs while you are elbow-deep in one order, without
   leaving the page or opening a modal:

     • the open tasks linked to THIS order, with their checklist steps tickable
       right here (one click = one PATCH, and the board updates);
     • "Mark done" on the task itself;
     • one-tap template buttons (COD confirmation, NDR follow-up…) that file a
       pre-filled task against this order;
     • a link that opens the task on the board inside the admin.

   Blocks are deliberately read-mostly: Shopify caps their height (~300px) and
   the block component set has no text inputs, so longer forms belong to the
   "Create task" action modal (taskpe-task-order), which this card points at.

   Merchants must add this block once: Order page → "Add custom app block" →
   TaskPe. That is also why the zero-setup action + bulk targets stay shipped.
   ========================================================================== */

const MAX_TASKS = 3;
const MAX_STEPS = 3;

export function createOrderTaskBlock() {
  return function extension() {
    render(<OrderTaskBlock />, document.body);
  };
}

/** Locale lookups must never blank the card when a key is missing. */
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

function fmtDue(iso) {
  if (!iso) return "";
  try {
    return new Date(iso).toLocaleDateString("en-IN", { day: "numeric", month: "short" });
  } catch (e) {
    return "";
  }
}

function OrderTaskBlock() {
  const shopify = globalThis.shopify;
  const { i18n } = shopify || {};

  const { gid, numericId, type } = firstResource(shopify);
  const resourceType = type || "order";

  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function load() {
    if (!numericId) return;
    try {
      const next = await api(`/resource-tasks?type=${resourceType}&id=${encodeURIComponent(numericId)}`);
      setData(next);
      setError("");
    } catch (e) {
      setError(e.message || tr(i18n, "unreachable", "Could not reach TaskPe."));
    }
  }

  useEffect(() => {
    void load();
  }, [numericId]);

  async function run(fn) {
    setBusy(true);
    try {
      await fn();
    } catch (e) {
      setError(e.message || tr(i18n, "failed", "That did not go through. Try again."));
    } finally {
      setBusy(false);
    }
  }

  /** Tick / untick one "- [ ]" line — the same PATCH the board itself makes. */
  function tick(task, index, done) {
    const item = (task.checklist || [])[index];
    if (!item) return;
    const lines = String(task.description || "").split("\n");
    lines[item.line] = `- [${done ? "x" : " "}] ${item.text}`;

    void run(async () => {
      await api(`/tasks/${task.id}`, { method: "PATCH", body: { description: lines.join("\n") } });
      await load();
    });
  }

  function markDone(task) {
    void run(async () => {
      await api(`/tasks/${task.id}/complete`, { method: "POST" });
      toast(shopify, "Task completed");
      await load();
    });
  }

  /** One template button = one fully-formed task, linked, due, checklist ready. */
  function addFromTemplate(key) {
    void run(async () => {
      const res = await api("/tasks/bulk", {
        method: "POST",
        body: {
          template: key,
          resources: [{ type: resourceType, id: Number(numericId), gid }],
        },
      });
      toast(shopify, res?.created ? "Task added to TaskPe" : "Already on the board");
      await load();
    });
  }

  if (urlIsPlaceholder()) {
    return (
      <s-admin-block heading="TaskPe">
        <s-banner tone="critical">
          This bundle has no backend address. Set `APP_URL` in
          extensions/shared/api.js to the same https:// domain as APP_URL in the
          Laravel .env, then run shopify app deploy again.
        </s-banner>
      </s-admin-block>
    );
  }

  const open = (data?.open || []).slice(0, MAX_TASKS);
  const hidden = Math.max(0, (data?.open || []).length - MAX_TASKS);
  const templates = (data?.templates || []).slice(0, 3);

  return (
    <s-admin-block
      heading={tr(i18n, "name", "TaskPe")}
      collapsedSummary={
        open.length
          ? `${open.length} open ${open.length === 1 ? "task" : "tasks"}`
          : tr(i18n, "collapsed-empty", "No open tasks")
      }
    >
      <s-box padding="base">
        <s-stack gap="base">
          {error ? <s-banner tone="critical">{error}</s-banner> : null}

          {data?.plan?.full ? (
            <s-banner tone="warning">
              {tr(i18n, "plan-full", "Your plan's open-task limit is reached — upgrade to add more.")}
            </s-banner>
          ) : null}

          {!error && !open.length ? (
            <s-text appearance="subdued">{tr(i18n, "none", "No open tasks for this order.")}</s-text>
          ) : null}

          {open.map((task) => (
            <s-box padding="base">
              <s-stack gap="small">
                <s-stack direction="inline" gap="base">
                  <s-link href={task.open_url}>{task.title}</s-link>
                  {task.step_count ? (
                    <s-text appearance="subdued">
                      {task.done_count}/{task.step_count}
                    </s-text>
                  ) : null}
                </s-stack>

                {(task.checklist || []).slice(0, MAX_STEPS).map((item, i) => (
                  <s-checkbox
                    checked={!!item.done}
                    disabled={busy}
                    onChange={(event) => tick(task, i, event.target.checked)}
                  >
                    {item.text}
                  </s-checkbox>
                ))}

                {(task.checklist || []).length > MAX_STEPS ? (
                  <s-text appearance="subdued">
                    +{(task.checklist || []).length - MAX_STEPS} more steps on the board
                  </s-text>
                ) : null}

                <s-stack direction="inline" gap="base">
                  <s-button size="slim" variant="secondary" disabled={busy} onClick={() => markDone(task)}>
                    {tr(i18n, "mark-done", "Mark done")}
                  </s-button>
                  {task.assignee?.name ? (
                    <s-text appearance="subdued">{task.assignee.name}</s-text>
                  ) : null}
                  {task.due_at ? (
                    <s-text appearance="subdued">
                      {task.overdue ? "overdue — " : "due "}
                      {fmtDue(task.due_at)}
                    </s-text>
                  ) : null}
                </s-stack>
              </s-stack>
            </s-box>
          ))}

          {hidden > 0 ? (
            <s-text appearance="subdued">
              {hidden} more open {hidden === 1 ? "task" : "tasks"}
            </s-text>
          ) : null}

          <s-divider />

          {templates.length ? (
            <s-stack gap="small">
              <s-text appearance="subdued">{tr(i18n, "add-from-template", "Add a task for this order:")}</s-text>
              <s-stack direction="inline" gap="base">
                {templates.map((tpl) => (
                  <s-button size="slim" disabled={busy} onClick={() => addFromTemplate(tpl.key)}>
                    {tpl.name}
                    {tpl.steps ? ` (${tpl.steps})` : ""}
                  </s-button>
                ))}
              </s-stack>
            </s-stack>
          ) : null}

          {data?.board_url ? (
            <s-button size="slim" variant="tertiary" onClick={() => openLink(shopify, data.board_url)}>
              {tr(i18n, "open-board", "Open TaskPe board")}
            </s-button>
          ) : null}
        </s-stack>
      </s-box>
    </s-admin-block>
  );
}
