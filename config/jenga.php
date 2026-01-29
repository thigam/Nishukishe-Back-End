<?php

return [

    'env' => env('JENGA_ENV', 'sandbox'),

    'base_url' => env('JENGA_BASE_URL', 'https://uat.finserve.africa'),
    'auth_url' => env('JENGA_AUTH_URL', 'https://uat.finserve.africa/authentication/api/v3/authenticate/merchant'),

    'api_key'         => env('JENGA_API_KEY'),
    'merchant_code'   => env('JENGA_MERCHANT_CODE'),
    'merchant_name'   => env('JENGA_MERCHANT_NAME', 'Nishukishe Ltd'),
    'consumer_secret' => env('JENGA_CONSUMER_SECRET'),

    'private_key_path'        => env('JENGA_PRIVATE_KEY_PATH', storage_path('certificates/jenga_private.pem')),
    'equity_account_number'   => env('JENGA_EQUITY_ACCOUNT_NUMBER'),
    'callback_url'            => env('JENGA_CALLBACK_URL', env('APP_URL') . '/api/jenga/callback'),
    'default_telco'           => env('JENGA_DEFAULT_TELCO', 'Safaricom'),

    // Where card customers land after paying
    'card_redirect_url'       => env('JENGA_CARD_REDIRECT_URL', env('FRONTEND_URL') . '/tembea/booking/complete'),
    'wallet_stk_endpoint' => '/api-checkout/mpesa-stk-push/v3.0/init',
    // Default country codes / locale-related
    'country_code'            => env('JENGA_COUNTRY_CODE', 'KE'),
    'customer_postal_code'    => env('JENGA_CUSTOMER_POSTAL_CODE', '00100'),
];

