<?php

$staffOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'STAFF_CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,https://staging-team.lyonsbowe.ai,https://team.lyonsbowe.ai',
    )),
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $staffOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
