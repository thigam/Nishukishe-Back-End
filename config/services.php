<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'facebook' => [
        'page_id' => env('FACEBOOK_PAGE_ID'),
        'access_token' => env('FACEBOOK_ACCESS_TOKEN'),
        'graph_url' => env('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com/v19.0'),
    ],

    'instagram' => [
        'business_id' => env('INSTAGRAM_BUSINESS_ID'),
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        'graph_url' => env('INSTAGRAM_GRAPH_URL', 'https://graph.facebook.com/v19.0'),
    ],

    'x' => [
        'user_id' => env('X_USER_ID'),
        'bearer_token' => env('X_BEARER_TOKEN'),
        'base_url' => env('X_API_BASE', 'https://api.twitter.com/2'),
    ],

    'youtube' => [
        'channel_id' => env('YOUTUBE_CHANNEL_ID'),
        'api_key' => env('YOUTUBE_API_KEY'),
        'base_url' => env('YOUTUBE_API_BASE', 'https://www.googleapis.com/youtube/v3'),
    ],

    'linkedin' => [
        'organization_id' => env('LINKEDIN_ORGANIZATION_ID'),
        'access_token' => env('LINKEDIN_ACCESS_TOKEN'),
        'base_url' => env('LINKEDIN_API_BASE', 'https://api.linkedin.com'),
        'display_name' => env('LINKEDIN_DISPLAY_NAME'),
        'handle' => env('LINKEDIN_HANDLE'),
        'profile_url' => env('LINKEDIN_PROFILE_URL'),
        'avatar_url' => env('LINKEDIN_AVATAR_URL'),
    ],

    'tiktok' => [
        'business_id' => env('TIKTOK_BUSINESS_ID'),
        'access_token' => env('TIKTOK_ACCESS_TOKEN'),
        'base_url' => env('TIKTOK_API_BASE', 'https://business-api.tiktok.com'),
    ],

    'bookings' => [
        'allow_manual_checkout' => env('BOOKINGS_ALLOW_MANUAL_CHECKOUT', true),
        'tembea_commission_rate' => (float) env('BOOKINGS_TEMBEA_COMMISSION_RATE', 0.025),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'frontend_redirect' => env('GOOGLE_FRONTEND_REDIRECT', env('FRONTEND_URL') . '/login/google/callback'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'merchant_email' => env('PAYSTACK_MERCHANT_EMAIL'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    ],

    'mapbox' => [
        'token' => env('NEXT_PUBLIC_MAPBOX_TOKEN'),
    ],

];
