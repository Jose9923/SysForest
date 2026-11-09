<?php

declare(strict_types=1);

return [
    'admin_email' => env('SYSFOREST_BASE_ADMIN_EMAIL', 'varcor.soluciones@gmail.com'),
    'admin_name' => env('SYSFOREST_BASE_ADMIN_NAME', 'Varcor'),
    // La credencial inicial permanece en el entorno privado, nunca en Git.
    'admin_password' => env('SYSFOREST_BASE_ADMIN_PASSWORD'),
];
