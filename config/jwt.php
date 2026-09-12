<?php

use PHPOpenSourceSaver\JWTAuth\Providers\Auth\Illuminate as AuthProvider;
use PHPOpenSourceSaver\JWTAuth\Providers\JWT\Lcobucci;
use PHPOpenSourceSaver\JWTAuth\Providers\Storage\Illuminate as StorageProvider;

return [
    'secret' => env('JWT_SECRET'),
    'keys' => ['public' => null, 'private' => null, 'passphrase' => null],
    'algo' => 'HS256',
    'ttl' => max(1, (int) env('JWT_TTL', 10)),
    'required_claims' => ['iss', 'iat', 'exp', 'nbf', 'sub', 'jti', 'sid', 'aud', 'prv'],
    'lock_subject' => true,
    'leeway' => 0,
    // SYSFOREST rota refresh opacos en PostgreSQL; no expone el refresh JWT del paquete.
    'refresh_ttl' => 0,
    'refresh_iat' => false,
    'persistent_claims' => [],
    'blacklist_enabled' => false,
    'blacklist_grace_period' => 0,
    'show_black_list_exception' => false,
    'decrypt_cookies' => false,
    'cookie_key_name' => 'sysforest_access',
    'providers' => ['jwt' => Lcobucci::class, 'auth' => AuthProvider::class, 'storage' => StorageProvider::class],
];
