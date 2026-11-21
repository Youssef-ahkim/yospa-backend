<?php

$allowedOrigins = [];
if (env('CORS_ALLOWED_ORIGINS')) {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS')))));
} elseif (env('APP_ENV') === 'local') {
    $allowedOrigins = ['http://localhost:3000'];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
