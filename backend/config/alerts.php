<?php

return [
    // Log levels that become owner alerts (Inbox → System). Lower levels are ignored.
    'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],

    // Levels that also email / WhatsApp the owner (recipients are set in Owner portal → Settings → Alerts).
    'notify_levels' => ['error', 'critical', 'alert', 'emergency'],

    // The same problem notifies again at most this often while it stays open.
    'renotify_minutes' => (int) env('ALERT_RENOTIFY_MINUTES', 360),

    // Approved Utility template on the platform WhatsApp number, with one body variable {{1}}.
    'whatsapp_template' => env('ALERT_WHATSAPP_TEMPLATE', 'system_alert'),
    // Good news for the owner (new join request); falls back to whatsapp_template until approved.
    'notice_template' => env('ALERT_WHATSAPP_NOTICE_TEMPLATE', 'neotalab_update'),
    'whatsapp_language' => env('ALERT_WHATSAPP_LANGUAGE', 'en'),
];
