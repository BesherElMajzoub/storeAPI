<?php

return [

    /*
    | Minutes after checkout during which a customer may cancel without admin
    | approval. Held card payments are captured once it closes. Must be 180
    | in production; shorten it only for acceptance testing.
    */
    'direct_cancel_window_minutes' => (int) env('ORDER_DIRECT_CANCEL_WINDOW_MINUTES', 180),

    // Alert the admin when an order is still only authorized after this long.
    'stale_authorization_alert_hours' => (int) env('ORDER_STALE_AUTHORIZATION_ALERT_HOURS', 6),

    // Capture attempts that fail for transient reasons before the admin is alerted.
    'capture_max_attempts' => (int) env('ORDER_CAPTURE_MAX_ATTEMPTS', 3),

    // Timezone used for dates shown to customers in emails.
    'store_timezone' => env('STORE_TIMEZONE', 'America/Los_Angeles'),

];
