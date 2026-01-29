<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramProvider extends AbstractProvider
{
    public function platform(): string
    {
        return 'instagram';
    }

    protected function doFetch(CarbonInterface $since): array
    {
        $userId = $this->config['business_id'] ?? config('services.instagram.business_id');
        $token = $this->config['access_token'] ?? config('services.instagram.access_token');
        $graphUrl = $this->config['graph_url'] ?? config('services.instagram.graph_url', 'https://graph.facebook.com/v19.0');

        if (! $userId || ! $token) {
            throw new RuntimeException('Missing Instagram credentials.');
        }

        $account = Http::baseUrl($graphUrl)
            ->withToken($token)
            ->get("/{$userId}", [
                'fields' => 'id,username,name,profile_picture_url,followers_count',
            ])
            ->throw()
            ->json();

        $media = Http::baseUrl($graphUrl)
            ->withToken($token)
            ->get("/{$userId}/media", [
                'since' => $since->timestamp,
                'limit' => $this->config['limit'] ?? 25,
                'fields' => 'id,caption,timestamp,permalink,media_type,like_count,comments_count,video_views,saved',
            ])
            ->throw()
            ->json('data', []);

        $posts = [];
        $aggregate = [
            'followers' => (int) ($account['followers_count'] ?? 0),
            'post_count' => count($media),
            'likes' => 0,
            'comments' => 0,
            'views' => 0,
            'saves' => 0,
        ];

        foreach ($media as $item) {
            $likes = (int) ($item['like_count'] ?? 0);
            $comments = (int) ($item['comments_count'] ?? 0);
            $views = (int) ($item['video_views'] ?? $item['plays'] ?? 0);
            $saves = (int) ($item['saved'] ?? Arr::get($item, 'insights.saved') ?? 0);

            $posts[] = [
                'external_id' => $item['id'] ?? null,
                'permalink' => $item['permalink'] ?? null,
                'message' => $item['caption'] ?? null,
                'published_at' => $item['timestamp'] ?? null,
                'metrics' => [
                    'likes' => $likes,
                    'comments' => $comments,
                    'views' => $views,
                    'saves' => $saves,
                ],
            ];

            $aggregate['likes'] += $likes;
            $aggregate['comments'] += $comments;
            $aggregate['views'] += $views;
            $aggregate['saves'] += $saves;
        }

        return [
            'collected_at' => now()->toIso8601String(),
            'account' => [
                'external_id' => $account['id'] ?? (string) $userId,
                'display_name' => $account['name'] ?? $account['username'] ?? null,
                'username' => $account['username'] ?? null,
                'profile_url' => isset($account['username']) ? sprintf('https://instagram.com/%s', $account['username']) : null,
                'avatar_url' => $account['profile_picture_url'] ?? null,
                'followers' => $aggregate['followers'],
            ],
            'metrics' => $aggregate,
            'posts' => $posts,
        ];
    }
}
