<?php

return [
    'version' => '1.0',
    'browser_cookie_name' => env('YANJI_BROWSER_COOKIE_NAME', 'geo_session'),
    'portable' => env('YANJI_PORTABLE_INSTANCE', false),
    'portable_root' => env('YANJI_ENV_PATH', ''),
    'desktop_mode' => env('YANJI_DESKTOP_MODE', false),
    'terms_url' => env('YANJI_TERMS_URL', ''),
    'privacy_url' => env('YANJI_PRIVACY_URL', ''),
    'website_url' => env('YANJI_WEBSITE_URL', ''),
];
