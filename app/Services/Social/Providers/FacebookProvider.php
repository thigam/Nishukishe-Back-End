<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FacebookProvider extends AbstractProvider
{
    public function platform(): string
    {
        return 'facebook';
    }

    protected function doFetch(CarbonInterface $since): array
    {
        $pageId = $this->config['page_id'] ?? config('services.facebook.page_id');
        $token = $this->config['access_token'] ?? config('services.facebook.access_token');
        $graphUrl = $this->config['graph_url'] ?? config('services.facebook.graph_url', 'https://graph.facebook.com/v19.0');

        if (! $pageId || ! $token) {
            throw new RuntimeException('Missing Facebook credentials.');
        }

        $accountResponse = Http::baseUrl($graphUrl)
            ->withToken($token)
            ->get("/{$pageId}", [
                'fields' => 'id,name,fan_count,followers_count,link,picture{url}',
            ])
            ->throw()
            ->json();

        $postsResponse = Http::baseUrl($graphUrl)
            ->withToken($token)
            ->get("/{$pageId}/posts", [
                'since' => $since->timestamp,
                'limit' => $this->config['limit'] ?? 25,
                'fields' => 'id,created_time,message,permalink_url,shares,comments.summary(true).limit(0),insights.metric(post_impressions,post_engaged_users,post_reactions_by_type_total)',
            ])
            ->throw()
            ->json('data', []);

        $posts = [];
        $aggregate = [
            'followers' => (int) ($accountResponse['followers_count'] ?? $accountResponse['fan_count'] ?? 0),
            'post_count' => count($postsResponse),
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'views' => 0,
            'reactions' => 0,
        ];

        foreach ($postsResponse as $postData) {
            $insights = $this->parseInsights($postData['insights'] ?? []);
            $likes = (int) ($insights['post_reactions_by_type_total'] ?? 0);
            $views = (int) ($insights['post_impressions'] ?? 0);
            $engaged = (int) ($insights['post_engaged_users'] ?? 0);
            $comments = (int) (Arr::get($postData, 'comments.summary.total_count') ?? 0);
            $shares = (int) (Arr::get($postData, 'shares.count') ?? 0);

            $posts[] = [
                'external_id' => $postData['id'] ?? null,
                'permalink' => $postData['permalink_url'] ?? null,
                'message' => $postData['message'] ?? null,
                'published_at' => $postData['created_time'] ?? null,
                'metrics' => [
                    'likes' => $likes,
                    'comments' => $comments,
                    'shares' => $shares,
                    'views' => $views,
                    'reactions' => $likes,
                    'engagement' => $engaged,
                ],
            ];

            $aggregate['likes'] += $likes;
            $aggregate['comments'] += $comments;
            $aggregate['shares'] += $shares;
            $aggregate['views'] += $views;
            $aggregate['reactions'] += $likes;
        }

        return [
            'collected_at' => now()->toIso8601String(),
            'account' => [
                'external_id' => $accountResponse['id'] ?? (string) $pageId,
                'display_name' => $accountResponse['name'] ?? null,
                'username' => $accountResponse['name'] ?? null,
                'profile_url' => $accountResponse['link'] ?? null,
                'avatar_url' => Arr::get($accountResponse, 'picture.data.url'),
                'followers' => $aggregate['followers'],
            ],
            'metrics' => $aggregate,
            'posts' => $posts,
        ];
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return array<string, int>
     */
    private function parseInsights(array $insights): array
    {
        $results = [];

        foreach ($insights['data'] ?? [] as $entry) {
            $name = $entry['name'] ?? null;
            if (! $name) {
                continue;
            }

            $values = $entry['values'] ?? [];
            $latest = is_array($values) ? end($values) : null;
            if (! is_array($latest)) {
                $latest = [];
            }

            $value = $latest['value'] ?? 0;

            if (is_array($value)) {
                $value = array_sum(array_filter($value, fn ($item) => is_numeric($item)));
            }

            $results[$name] = is_numeric($value) ? (int) $value : 0;
        }

        return $results;
    }
}
