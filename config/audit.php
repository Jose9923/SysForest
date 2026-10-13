<?php

declare(strict_types=1);

return [
    'store' => env('AUDIT_STORE', 'postgres'),
    'batch_size' => (int) env('AUDIT_BATCH_SIZE', 500),
    'max_export_rows' => 50000,
    'export_rate_limit' => 6,
    'mongodb' => [
        'uri' => env('AUDIT_MONGO_URI', 'mongodb://mongodb:27017'),
        'database' => env('AUDIT_MONGO_DATABASE', 'sysforest_audit'),
        'collection' => env('AUDIT_MONGO_COLLECTION', 'activity_logs'),
        'username' => env('AUDIT_MONGO_USERNAME', 'sysforest_audit_app'),
        'password' => env('AUDIT_MONGO_PASSWORD'),
        'auth_source' => env('AUDIT_MONGO_AUTH_SOURCE', env('AUDIT_MONGO_DATABASE', 'sysforest_audit')),
        'timeout_ms' => 1000,
        'query_timeout_ms' => 3000,
    ],
];
