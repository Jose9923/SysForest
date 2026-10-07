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
        ['id' => 'opentopomap', 'name' => 'Relieve · OpenTopoMap',
            'url' => 'https://a.tile.opentopomap.org/{z}/{x}/{y}.png',
            'attribution' => 'Datos: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>, SRTM | Mapa: &copy; <a href="https://opentopomap.org/about">OpenTopoMap</a> (CC-BY-SA)',
            'max_zoom' => 17,
            'notice' => 'Relieve y curvas de nivel de OpenTopoMap. Servicio comunitario de referencia, sin garantía de disponibilidad.'],
        ['id' => 'nasa', 'name' => 'Satélite NASA · referencia 2004',
            'url' => 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief_Bathymetry/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg',
            'attribution' => 'Imagen: <a href="https://science.nasa.gov/earth/earth-observatory/blue-marble-next-generation/topography-bathymetry-maps/">NASA Earth Observatory</a> / <a href="https://www.earthdata.nasa.gov/data/tools/gibs">GIBS</a> · agosto de 2004',
            'max_zoom' => 8,
            'notice' => 'Mosaico satelital histórico de agosto de 2004. Es una referencia regional de baja resolución: no muestra árboles individuales ni cambios recientes.'],
    ],
];
