<?php

declare(strict_types=1);

return [
    // Los límites acotan la respuesta, nunca sustituyen el conteo del conjunto filtrado.
    'max_points' => 2000,
    'grid_cells_per_axis' => 64,
    'cluster_zoom_threshold' => 10,
    'debounce_ms' => 300,
    'rate_limit' => 180,
    'heat_gradient' => ['0.2' => '#2b83ba', '0.45' => '#abdda4', '0.7' => '#fdae61', '1' => '#d7191c'],
    'default_view' => ['center' => [0, 0], 'zoom' => 2],
    'colors' => [
        'active' => '#247858',
        'under_observation' => '#b87913',
        'dead' => '#667085',
        'felled' => '#a33b3b',
        'transplanted' => '#386cb0',
        'removed' => '#7c5d88',
        'replaced' => '#087e8b',
    ],
    // Cada proveedor adicional debe tener URL, atribución y condiciones de uso verificadas.
    'base_layers' => [
        ['id' => 'osm', 'name' => 'OpenStreetMap',
            'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
            'max_zoom' => 19],
    ],
];
