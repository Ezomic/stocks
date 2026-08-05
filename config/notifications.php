<?php

return [
    'enabled' => (bool) env('STOCKS_NOTIFICATIONS_ENABLED', true),

    /*
     * Notification channels for order events. Comma separated, e.g. "mail". Leave empty to
     * keep the log line without delivering anything.
     */
    'channels' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STOCKS_NOTIFICATION_CHANNELS', 'mail'))
    ))),

    /*
     * Which order events are worth interrupting someone for.
     */
    'events' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STOCKS_NOTIFICATION_EVENTS', 'placed,filled,failed'))
    ))),
];
