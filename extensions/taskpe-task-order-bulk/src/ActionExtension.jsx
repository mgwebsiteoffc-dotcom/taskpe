// Thin wrapper — everything lives in extensions/shared/BulkCreateTasks.jsx.
// The argument is the pre-selected template for COD orders (the biggest RTO
// saving); the merchant can still switch it in the modal.
import "@shopify/ui-extensions/preact";
import { createBulkTaskExtension } from "../../shared/BulkCreateTasks.jsx";

export default createBulkTaskExtension("cod-confirm");
