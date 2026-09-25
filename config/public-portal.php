<?php

declare(strict_types=1);

return [
    'page_size' => 20,
    'rate_limits' => ['pages' => 90, 'api' => 180, 'media' => 240],
    // Derivados privados: cada entrega revalida publicación antes de permitir incluso un 304.
    'image_quality' => 85,
    'image_cache_seconds' => 3600,
];
