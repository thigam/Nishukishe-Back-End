<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XProvider extends AbstractProvider
{
    public function platform(): string
    {
        return 'x';
    }

    protected function doFetch(CarbonInterface $since): array
    {
        $userId = $this->config['user_id'] ?? config('services.x.user_id');
        $token = $this->config['bearer_token'] ?? config('services.x.bearer_token');
        $baseUrl = $this->config['base_url'] ?? 'https://api.twitter.com/2';

        if (! $userId || ! $token) {
            throw new RuntimeException('Missing X API credentials.');
        }

        $account = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->get("/users/{$userId}", [
                'user.fields' => 'name,username,profile_image_url,public_metrics,url',
            ])
            ->throw()
            ->json('data');

        if (! is_array($account)) {
            throw new RuntimeException('Invalid X account response.');
        }

        $tweets = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->get("/users/{$userId}/tweets", [
                'tweet.fields' => 'created_at,public_metrics',
                'max_results' => $this->config['limit'] ?? 50,
                'start_time' => $since->toIso8601String(),
                'exclude' => 'replies',
            ])
            ->throw()
            ->json('data', []);

        $posts = [];
        $aggregate = [
            'followers' => (int) Arr::get($account, 'public_metrics.followers_count', 0),
            'post_count' => count($tweets),
            'likes' => 0,
            'replies' => 0,
            'reposts' => 0,
            'quotes' => 0,
            'impressions' => 0,
        ];

        foreach ($tweets as $tweet) {
            $metrics = Arr::get($tweet, 'public_metrics', []);
            $likes = (int) ($metrics['like_count'] ?? 0);
            $replies = (int) ($metrics['reply_count'] ?? 0);
            $retweets = (int) ($metrics['retweet_count'] ?? 0);
            $quotes = (int) ($metrics['quote_count'] ?? 0);
            $impressions = (int) ($metrics['impression_count'] ?? 0);

            $posts[] = [
                'external_id' => $tweet['id'] ?? null,
                'permalink' => isset($tweet['id']) && isset($account['username'])
                    ? sprintf('https://x.com/%s/status/%s', $account['username'], $tweet['id'])
                    : null,
                'message' => $tweet['text'] ?? null,
                'published_at' => $tweet['created_at'] ?? null,
                'metrics' => [
                    'likes' => $likes,
                    'replies' => $replies,
                    'reposts' => $retweets,
                    'quotes' => $quotes,
                    'impressions' => $impressions,
                ],
            ];

            $aggregate['likes'] += $likes;
            $aggregate['replies'] += $replies;
            $aggregate['reposts'] += $retweets;
            $aggregate['quotes'] += $quotes;
            $aggregate['impressions'] += $impressions;
        }

        return [
            'collected_at' => now()->toIso8601String(),
            'account' => [
                'external_id' => $account['id'] ?? (string) $userId,
                'display_name' => $account['name'] ?? null,
                'username' => $account['username'] ?? null,
                'profile_url' => $account['url'] ?? (isset($account['username']) ? sprintf('https://x.com/%s', $account['username']) : null),
                'avatar_url' => $account['profile_image_url'] ?? null,
                'followers' => $aggregate['followers'],
            ],
            'metrics' => $aggregate,
            'posts' => $posts,
        ];
    }
}
