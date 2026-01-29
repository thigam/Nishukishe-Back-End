<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or “CORS”. This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    // match your API endpoints
    'paths' => ['api/*', 'auth/*', 'sacco/*', 'routes/*', 'stops/*', 'sanctum/csrf-cookie', 'login', 'logout', '*'],

    // allow all HTTP verbs
    'allowed_methods' => ['*'],

    // allow requests from specific frontends
    'allowed_origins' => [
	    'https://frontend.nishy.test',
        'http://nishukishe.com',
        'https://nishukishe.com',
        'http://dev.nishukishe.com',
        'https://dev.nishukishe.com',
        'http://localhost:3000',
        'https://localhost:3000',
        'https://backend.nishukishe.com',
        'https://front.moskwito.com',
         'https://images.nishukishe.com',        
    ],

    // allow any headers
    'allowed_headers' => ['*'],

    // enable cookies for cross-site requests
 
    'allowed_origins_patterns' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

