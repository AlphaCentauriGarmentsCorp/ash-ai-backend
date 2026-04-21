<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Share Frontend URL
    |--------------------------------------------------------------------------
    |
    | Base frontend URL used when generating token-based public quotation links.
    | Keep this separate from CORS and APP_URL so share links can point to
    | the correct frontend host/port per environment.
    |
    */
    'frontend_url' => env('SHARE_FRONTEND_URL', env('FRONTEND_URL', env('APP_URL', 'http://localhost:5174'))),
];
