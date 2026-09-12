<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    // Redis conserva CSRF y selección institucional; la identidad se valida mediante JWT y auth_sessions.
    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    // El cifrado depende de la APP_KEY persistente compartida por los contenedores.
    'encrypt' => env('SESSION_ENCRYPT', false),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'SYSFOREST')).'-session'
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    // HTTP local admite false; al servir mediante HTTPS debe configurarse true.
    'secure' => env('APP_ENV') !== 'local' || str_starts_with((string) env('APP_URL'), 'https://'),

    'http_only' => env('SESSION_HTTP_ONLY', true),

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

    // JSON evita la deserialización de objetos PHP desde la sesión.
    'serialization' => 'json',

];
