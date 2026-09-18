<?php

declare(strict_types=1);

return [
    'disk' => env('EVIDENCE_DISK', 'evidences'),
    'max_size_mb' => (int) env('EVIDENCE_MAX_SIZE_MB', 10),
    'max_files' => (int) env('EVIDENCE_MAX_FILES', 5),
    'max_batch_size_mb' => (int) env('EVIDENCE_MAX_BATCH_SIZE_MB', 50),
    'max_pixels' => (int) env('EVIDENCE_MAX_PIXELS', 20000000),
    'allowed_mimes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'EVIDENCE_ALLOWED_MIMES',
        'image/jpeg,image/png,image/webp',
    ))))),
];
