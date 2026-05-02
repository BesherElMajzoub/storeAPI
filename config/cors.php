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

    'allowed_origins' => [
        'http://localhost:5173',    // Vite React dev server
        'http://localhost:3000',    // CRA / Next.js dev server
        'http://localhost:8000',    // Swagger / Local Laravel server
        'http://127.0.0.1:8000',    // Swagger / Local Laravel server
        env('FRONTEND_URL', 'http://localhost:5173'),
    ],

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
