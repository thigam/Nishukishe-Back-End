<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeProvider extends AbstractProvider
{
    public function platform(): string
    {
        return 'youtube';
    }

    protected function doFetch(CarbonInterface $since): array
    {
        $channelId = $this->config['channel_id'] ?? config('services.youtube.channel_id');
        $apiKey = $this->config['api_key'] ?? config('services.youtube.api_key');
        $baseUrl = $this->config['base_url'] ?? 'https://www.googleapis.com/youtube/v3';

        if (! $channelId || ! $apiKey) {
            throw new RuntimeException('Missing YouTube API configuration.');
        }

        $channel = Http::baseUrl($baseUrl)
            ->get('/channels', [
                'part' => 'snippet,statistics',
                'id' => $channelId,
                'key' => $apiKey,
            ])
            ->throw()
            ->json('items.0');

        if (! is_array($channel)) {
            throw new RuntimeException('Channel not found.');
        }

        $search = Http::baseUrl($baseUrl)
            ->get('/search', [
                'key' => $apiKey,
                'channelId' => $channelId,
                'part' => 'snippet',
                'order' => 'date',
                'maxResults' => $this->config['limit'] ?? 25,
                'publishedAfter' => $since->toIso8601String(),
                'type' => 'video',
            ])
            ->throw()
            ->json('items', []);

        $videoIds = array_values(array_filter(array_map(fn ($item) => Arr::get($item, 'id.videoId'), $search)));

        $videos = [];
        if (! empty($videoIds)) {
            $videos = Http::baseUrl($baseUrl)
                ->get('/videos', [
                    'key' => $apiKey,
                    'id' => implode(',', $videoIds),
                    'part' => 'snippet,statistics',
                ])
                ->throw()
                ->json('items', []);
        }

        $posts = [];
        $aggregate = [
            'followers' => (int) Arr::get($channel, 'statistics.subscriberCount', 0),
            'post_count' => count($videos),
            'likes' => 0,
            'comments' => 0,
            'views' => 0,
        ];

        foreach ($videos as $video) {
            $stats = Arr::get($video, 'statistics', []);
            $snippet = $video['snippet'] ?? [];
            $videoId = $video['id'] ?? null;

            $likes = (int) ($stats['likeCount'] ?? 0);
            $comments = (int) ($stats['commentCount'] ?? 0);
            $views = (int) ($stats['viewCount'] ?? 0);

            $posts[] = [
                'external_id' => $videoId,
                'permalink' => $videoId ? sprintf('https://youtube.com/watch?v=%s', $videoId) : null,
                'message' => $snippet['title'] ?? null,
                'published_at' => $snippet['publishedAt'] ?? null,
                'metrics' => [
                    'likes' => $likes,
                    'comments' => $comments,
                    'views' => $views,
                ],
            ];

            $aggregate['likes'] += $likes;
            $aggregate['comments'] += $comments;
            $aggregate['views'] += $views;
        }

        return [
            'collected_at' => now()->toIso8601String(),
            'account' => [
                'external_id' => $channel['id'] ?? $channelId,
                'display_name' => Arr::get($channel, 'snippet.title'),
                'username' => Arr::get($channel, 'snippet.customUrl') ? '@' . $channel['snippet']['customUrl'] : null,
                'profile_url' => Arr::get($channel, 'snippet.customUrl')
                    ? sprintf('https://youtube.com/%s', $channel['snippet']['customUrl'])
                    : sprintf('https://youtube.com/channel/%s', $channelId),
                'avatar_url' => Arr::get($channel, 'snippet.thumbnails.medium.url'),
                'followers' => $aggregate['followers'],
            ],
            'metrics' => $aggregate,
            'posts' => $posts,
        ];
    }
}
