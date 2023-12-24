<?php


return [

    'demo' => env('APP_DEMO', true),
    
    'version' => env('APP_VERSION'),

    'name' => env('APP_NAME', 'Laravel'),

    'mail' => env('APP_MAIL', true),

    'sms' => env('APP_SMS', true),

    'prefix' => env('ADMIN_PREFIX', 'admin'),

    'file_permission' => 0775,

    'api_latest' => 2,
];