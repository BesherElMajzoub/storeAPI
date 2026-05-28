<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Analytics Toggle
    |--------------------------------------------------------------------------
    |
    | Master switch to enable or disable visitor tracking system.
    |
    */
    'enabled' => env('ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | GeoIP Resolution
    |--------------------------------------------------------------------------
    |
    | If enabled, resolves visitor country/city/region using Geolocation APIs.
    |
    */
    'geoip_enabled' => env('ANALYTICS_GEOIP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Ignore Bots
    |--------------------------------------------------------------------------
    |
    | If true, common bot user agents (googlebot, bingbot, crawlers) are ignored.
    |
    */
    'ignore_bots' => env('ANALYTICS_IGNORE_BOTS', true),

    /*
    |--------------------------------------------------------------------------
    | Bot Signatures
    |--------------------------------------------------------------------------
    |
    | Substrings to identify bots in the User-Agent string.
    |
    */
    'bot_signatures' => [
        'bot',
        'crawl',
        'spider',
        'slurp',
        'yahoo',
        'mediapartners',
        'googlebot',
        'bingbot',
        'yandex',
        'baiduspider',
        'facebookexternalhit',
        'twitterbot',
        'rogerbot',
        'linkedinbot',
        'embedly',
        'quora link preview',
        'showyoubot',
        'outbrain',
        'pinterest/0.',
        'slackbot',
        'vkShare',
        'W3C_Validator',
        'redditbot',
        'Applebot',
        'WhatsApp',
        'TelegramBot',
        'discordbot',
        'copyleaks',
        'gptbot',
    ],
];
