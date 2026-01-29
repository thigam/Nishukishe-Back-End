<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TikTokProvider extends AbstractProvider
{
    public function platform(): string
    {
        return 'tiktok';
    }

    protected function doFetch(CarbonInterface $since): array
    {
        $accessToken = $this->config['access_token'] ?? config('services.tiktok.access_token');
        $businessId = $this->config['business_id'] ?? config('services.tiktok.business_id');
        $baseUrl = $this->config['base_url'] ?? 'https://business-api.tiktok.com';

        if (! $accessToken || ! $businessId) {
            throw new RuntimeException('Missing TikTok configuration.');
        }

        $client = Http::baseUrl($baseUrl)
            ->withHeaders([
                'Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ]);

        $accountResponse = $client
            ->post('/open_api/v1.3/business/get/', [
                'business_id' => $businessId,
            ])
            ->throw()
            ->json('data');

        $accountInfo = Arr::get($accountResponse, 'list.0');
        if (! is_array($accountInfo)) {
            throw new RuntimeException('TikTok account response missing.');
        }

        $statsResponse = $client
            ->post('/open_api/v1.3/insights/basic/data/', [
                'business_id' => $businessId,
                'start_date' => $since->copy()->startOfDay()->toDateString(),
                'end_date' => now()->toDateString(),
                'metrics' => ['follower_count', 'profile_views', 'video_views', 'likes', 'comments', 'shares'],
            ])
            ->throw()
            ->json('data.list', []);

        $aggregated = [
            'followers' => (int) Arr::get($accountInfo, 'follower_count', 0),
            'post_count' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'views' => 0,
            'saves' => 0,
        ];

        foreach ($statsResponse as $stat) {
            $metrics = Arr::get($stat, 'metrics', []);
            $aggregated['likes'] += (int) ($metrics['likes'] ?? 0);
            $aggregated['comments'] += (int) ($metrics['comments'] ?? 0);
            $aggregated['shares'] += (int) ($metrics['shares'] ?? 0);
            $aggregated['views'] += (int) ($metrics['video_views'] ?? 0);
        }

        $postsResponse = $client
            ->post('/open_api/v1.3/insights/video/list/', [
                'business_id' => $businessId,
                'start_date' => $since->copy()->startOfDay()->toDateString(),
                'end_date' => now()->toDateString(),
                'metrics' => ['likes', 'comments', 'shares', 'views', 'saves'],
                'page_size' => $this->config['limit'] ?? 20,
            ])
            ->throw()
            ->json('data.list', []);

        $posts = [];
        foreach ($postsResponse as $video) {
            $metrics = Arr::get($video, 'metrics', []);
            $permalink = Arr::get($video, 'share_url');

            $posts[] = [
                'external_id' => $video['video_id'] ?? null,
                'permalink' => $permalink,
                'message' => Arr::get($video, 'title'),
                'published_at' => Arr::get($video, 'publish_time'),
                'metrics' => [
                    'likes' => (int) ($metrics['likes'] ?? 0),
                    'comments' => (int) ($metrics['comments'] ?? 0),
                    'shares' => (int) ($metrics['shares'] ?? 0),
                    'views' => (int) ($metrics['views'] ?? 0),
                    'saves' => (int) ($metrics['saves'] ?? 0),
                ],
            ];
        }

        $aggregated['post_count'] = count($posts);

        return [
            'collected_at' => now()->toIso8601String(),
            'account' => [
                'external_id' => $accountInfo['business_id'] ?? $businessId,
                'display_name' => $accountInfo['display_name'] ?? null,
                'username' => $accountInfo['unique_id'] ?? null,
                'profile_url' => $accountInfo['profile_url'] ?? null,
                'avatar_url' => $accountInfo['avatar_url'] ?? null,
                'followers' => $aggregated['followers'],
            ],
            'metrics' => $aggregated,
            'posts' => $posts,
        ];
    }
}
