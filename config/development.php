<?php

declare(strict_types=1);

return [
    // Sin valores predeterminados: las credenciales se proporcionan únicamente en el entorno local.
    'accounts' => [
        'SUPER_ADMIN' => [
            'name' => 'Superadministrador de desarrollo',
            'email' => env('DEV_SUPER_ADMIN_EMAIL'),
            'password' => env('DEV_SUPER_ADMIN_PASSWORD'),
        ],
        'DEPARTMENT_ADMIN' => [
            'name' => 'Administrador departamental de desarrollo',
            'email' => env('DEV_DEPARTMENT_ADMIN_EMAIL'),
            'password' => env('DEV_DEPARTMENT_ADMIN_PASSWORD'),
        ],
        'ORGANIZATION_ADMIN' => [
            'name' => 'Administrador INEM de desarrollo',
            'email' => env('DEV_ORGANIZATION_ADMIN_EMAIL'),
            'password' => env('DEV_ORGANIZATION_ADMIN_PASSWORD'),
        ],
        'OFFICIAL' => [
            'name' => 'Funcionario INEM de desarrollo',
            'email' => env('DEV_OFFICIAL_EMAIL'),
            'password' => env('DEV_OFFICIAL_PASSWORD'),
        ],
    ],
];
