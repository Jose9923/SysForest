<?php

return [
    // Todos los TTL se expresan en minutos. Rotar no extiende el vencimiento de la sesión.
    'access_ttl_minutes' => max(1, (int) env('JWT_TTL', 10)),
    'session_ttl_minutes' => max(1, (int) env('AUTH_SESSION_TTL_MINUTES', 10080)),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost:8080')),
    'audience' => env('JWT_AUDIENCE', 'sysforest-web'),
    'login_rate_limit' => (int) env('AUTH_LOGIN_RATE_LIMIT', 10),
    'refresh_rate_limit' => (int) env('AUTH_REFRESH_RATE_LIMIT', 30),
    'password_rate_limit' => (int) env('AUTH_PASSWORD_RATE_LIMIT', 5),
    'invitation_rate_limit' => (int) env('AUTH_INVITATION_RATE_LIMIT', 20),
    'access_cookie' => 'sysforest_access',
    'refresh_cookie' => 'sysforest_refresh',
];
