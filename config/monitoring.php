<?php

// Thresholds for slow-request and slow-query warnings in the log.
return [
    'slow_request_ms' => (float) env('SLOW_REQUEST_MS', 1500),
    'slow_query_ms' => (float) env('SLOW_QUERY_MS', 250),
];
