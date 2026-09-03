<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel CORS Configuration
    |--------------------------------------------------------------------------
    |
    | زود origins الـ React dev و production هنا
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL', 'https://otantikqueen.com'),
        env('STAGING_FRONTEND_URL'),
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'http://localhost:5173' : null,
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'http://localhost:3000' : null,
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'http://localhost:8000' : null,
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'http://127.0.0.1:8000' : null,
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
     * صحيح: تسمح بإرسال الـ cookies مع الطلبات.
     * لازم يكون true إذا استخدمت Sanctum SPA.
     * مع Bearer token (الوضع الحالي) — يمكن تركه true بأمان.
     */
    'supports_credentials' => true,

];
