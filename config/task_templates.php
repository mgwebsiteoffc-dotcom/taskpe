<?php

/*
|--------------------------------------------------------------------------
| TaskPe — India D2C COD/NDR Template Pack
|--------------------------------------------------------------------------
| One-click task checklists for the workflows Indian D2C stores live in:
| COD confirmation (RTO prevention), NDR follow-ups, remittance
| reconciliation, prepaid conversion. Merchants tap a template, optionally
| link an order, and get a fully-prefilled task with a tickable checklist.
|
|   resource_type : which Shopify object the task should link to (null = none)
|   due_in_hours  : default due date offset from creation
|   title         : {order} and {date} placeholders are replaced at creation
|   icon          : key into the ICONS map in public/js/app.js (inline SVG,
|                   never emoji — emoji render differently per OS and cannot
|                   be tinted); fall back is "box";
|   checklist     : rendered as "- [ ] ..." lines in the task description;
|                   the board UI turns them into a clickable checklist
|
| `cod-confirm` is also used by the optional COD auto-task automation
| (Settings → COD automation), fired from the orders/create webhook.
*/

return [

    'cod-confirm' => [
        'icon'          => 'phone',
        'name'          => 'COD confirmation',
        'tagline'       => 'Verify a Cash-on-Delivery order before you ship — the #1 RTO killer.',
        'resource_type' => 'order',
        'priority'      => 'high',
        'due_in_hours'  => 24,
        'title'         => 'Confirm COD order {order}',
        'checklist'     => [
            'WhatsApp the customer: order summary + COD amount, ask them to reply YES to confirm',
            'No reply in 6 hours → call once on the shipping phone number',
            'Verify pin code + landmark are courier-deliverable',
            'Re-check the phone number digits (one typo = guaranteed RTO)',
            'Unreachable after 3 tries over 24h → hold or auto-cancel per store policy',
            'Add "COD confirmed" to order notes, then release for packing',
        ],
    ],

    'ndr-followup' => [
        'icon'          => 'refresh',
        'name'          => 'NDR follow-up',
        'tagline'       => 'Courier could not deliver — rescue the order before it becomes RTO.',
        'resource_type' => 'order',
        'priority'      => 'urgent',
        'due_in_hours'  => 12,
        'title'         => 'NDR follow-up: rescue {order}',
        'checklist'     => [
            'Note the exact NDR reason from the courier dashboard / daily NDR email',
            'Same-day call: confirm when someone will be available to receive',
            'Address unclear? Add landmark/house details to order notes for the rider',
            'Raise the re-attempt from your logistics panel (Delhivery/DTDC/XpressBees…)',
            'Day 3 undelivered → call again and offer: reschedule, self-pickup, or refund',
            'If RTO is unavoidable, record the reason to flag this phone/address for next time',
        ],
    ],

    'rto-high-risk' => [
        'icon'          => 'alert-circle',
        'name'          => 'High-risk order check',
        'tagline'       => 'New customer + COD + high value? Score the risk before dispatch.',
        'resource_type' => 'order',
        'priority'      => 'high',
        'due_in_hours'  => 24,
        'title'         => 'Risk-check order {order}',
        'checklist'     => [
            'Check address completeness: house no., street, pin code all present',
            'Phone number has 10 digits and accepts WhatsApp',
            'Look up past RTOs for this phone/pin in your courier panel',
            'WhatsApp order confirmation — no confirmation, no dispatch',
            'Very high value? Ask for a partial-prepaid (₹100–500) to lock intent',
            'Dispatch only after written confirmation on WhatsApp',
        ],
    ],

    'prepaid-convert' => [
        'icon'          => 'wallet',
        'name'          => 'Prepaid conversion',
        'tagline'       => 'Turn a COD order into a paid one — zero RTO risk, faster cash.',
        'resource_type' => 'order',
        'priority'      => 'medium',
        'due_in_hours'  => 24,
        'title'         => 'Convert {order} to prepaid',
        'checklist'     => [
            'WhatsApp an offer: small instant discount or free shipping for paying online',
            'Send the UPI / payment link with the order summary',
            'Payment received → mark the order prepaid before dispatch',
            'One gentle reminder after 12 hours — then stop (never spam)',
        ],
    ],

    'address-fix' => [
        'icon'          => 'map',
        'name'          => 'Address correction',
        'tagline'       => 'Customer asked to change address or phone — fix it before dispatch.',
        'resource_type' => 'order',
        'priority'      => 'high',
        'due_in_hours'  => 12,
        'title'         => 'Fix address for {order}',
        'checklist'     => [
            'Call/WhatsApp to confirm the corrected address + pin code verbatim',
            'Update the Shopify order BEFORE the shipping label is printed',
            'Label already generated? Re-create and re-download it',
            'Already shipped? Request address change with the courier — and warn the customer it is not guaranteed',
        ],
    ],

    'delayed-shipment' => [
        'icon'          => 'truck',
        'name'          => 'Delayed shipment save',
        'tagline'       => 'Package is stuck — tell the customer before they chase you.',
        'resource_type' => 'order',
        'priority'      => 'medium',
        'due_in_hours'  => 24,
        'title'         => 'Delayed shipment: {order}',
        'checklist'     => [
            'Find the cause from courier tracking: missed pickup / hub delay / weather',
            'Proactively WhatsApp a revised delivery date (do not wait for the complaint)',
            'More than 3 days late → offer a goodwill coupon for the next order',
            'Stuck 48h+ → escalate to your courier account manager',
        ],
    ],

    'cod-remittance' => [
        'icon'          => 'receipt',
        'name'          => 'COD remittance reconciliation',
        'tagline'       => 'Weekly: make sure the courier actually banked your COD cash.',
        'resource_type' => null,
        'priority'      => 'medium',
        'due_in_hours'  => 168, // one week
        'recur'         => 'weekly', // RecurringChores can auto-create this one
        'title'         => 'COD remittance check — week of {date}',
        'checklist'     => [
            'Download this week\'s remittance report from the courier panel',
            'Match: delivered-COD orders vs the credited amount',
            'Flag every mismatch / shortfall and raise a courier ticket with AWBs',
            'Target: 100% of delivered COD cash in your bank within T+7 days',
        ],
    ],

    'return-pickup' => [
        'icon'          => 'reopen',
        'name'          => 'Return / exchange pickup',
        'tagline'       => 'Coordinate the reverse pickup and close the loop fast.',
        'resource_type' => 'order',
        'priority'      => 'medium',
        'due_in_hours'  => 48,
        'title'         => 'Return pickup: {order}',
        'checklist'     => [
            'Confirm the return reason; collect unboxing video/photos for damage claims',
            'Create the reverse pickup; share AWB + pickup date with the customer',
            'QC on receipt: resellable → restock; damaged → log for claims',
            'Refund or ship the exchange within 48h of QC — and tell the customer it is done',
        ],
    ],
];
