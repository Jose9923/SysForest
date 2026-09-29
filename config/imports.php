<?php

declare(strict_types=1);

return [
    // El lote síncrono se valida y confirma completo, sin truncar filas ni dividir transacciones.
    'max_rows' => (int) env('IMPORTS_MAX_ROWS', 100),
    'max_upload_kb' => (int) env('IMPORTS_MAX_UPLOAD_KB', 5120),
    'max_uncompressed_bytes' => 32 * 1024 * 1024,
    'max_zip_entries' => 200,
    'max_cell_chars' => 32767,
    'preview_ttl_hours' => (int) env('IMPORTS_PREVIEW_TTL_HOURS', 24),
    'rate_limit' => (int) env('IMPORTS_RATE_LIMIT', 6),
];
