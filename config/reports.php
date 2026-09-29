<?php

declare(strict_types=1);

return [
    'page_size' => 25,
    'batch_size' => 200,
    'max_export_rows_public' => (int) env('REPORTS_PUBLIC_MAX_ROWS', 10000),
    'max_export_rows_private' => (int) env('REPORTS_PRIVATE_MAX_ROWS', 50000),
    'max_pdf_rows' => (int) env('REPORTS_PDF_MAX_ROWS', 200),
    'max_aggregate_rows' => (int) env('REPORTS_AGGREGATE_MAX_ROWS', 10000),
    'download_rate_public' => (int) env('REPORTS_PUBLIC_RATE_LIMIT', 6),
    'download_rate_private' => (int) env('REPORTS_PRIVATE_RATE_LIMIT', 12),
    'temporary_ttl_seconds' => 3600,
];
