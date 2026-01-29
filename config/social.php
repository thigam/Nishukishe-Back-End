<?php

return [
    'platforms' => [
        'facebook' => [
            'label' => 'Facebook',
            'provider' => \App\Services\Social\Providers\FacebookProvider::class,
        ],
        'instagram' => [
            'label' => 'Instagram',
            'provider' => \App\Services\Social\Providers\InstagramProvider::class,
        ],
        'x' => [
            'label' => 'X',
            'provider' => \App\Services\Social\Providers\XProvider::class,
        ],
        'youtube' => [
            'label' => 'YouTube',
            'provider' => \App\Services\Social\Providers\YouTubeProvider::class,
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'provider' => \App\Services\Social\Providers\LinkedInProvider::class,
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'provider' => \App\Services\Social\Providers\TikTokProvider::class,
        ],
    ],

    'default_weights' => [
        'likes' => 1.0,
        'comments' => 2.0,
        'shares' => 3.0,
        'views' => 0.05,
        'saves' => 1.5,
        'reposts' => 2.5,
        'reactions' => 1.2,
        'clicks' => 1.0,
    ],

    'platform_weights' => [
        'facebook' => [
            'likes' => 1.0,
            'comments' => 2.0,
            'shares' => 3.0,
            'views' => 0.05,
            'reactions' => 1.2,
        ],
        'instagram' => [
            'likes' => 1.0,
            'comments' => 2.0,
            'views' => 0.03,
            'saves' => 1.5,
        ],
        'x' => [
            'likes' => 1.0,
            'reposts' => 3.0,
            'quotes' => 2.5,
            'replies' => 2.0,
            'impressions' => 0.02,
        ],
        'youtube' => [
            'likes' => 1.0,
            'comments' => 2.0,
            'views' => 0.01,
        ],
        'linkedin' => [
            'likes' => 1.0,
            'comments' => 2.0,
            'shares' => 3.0,
            'clicks' => 1.5,
            'impressions' => 0.02,
        ],
        'tiktok' => [
            'likes' => 1.0,
            'comments' => 2.0,
            'shares' => 3.0,
            'views' => 0.02,
            'saves' => 1.8,
        ],
    ],

    'stubs_path' => storage_path('app/social/stubs'),
];
