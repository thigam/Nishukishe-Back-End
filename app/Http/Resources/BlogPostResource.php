<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin \App\Models\BlogPost */
class BlogPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latestVersion = $this->relationLoaded('latestVersion') ? $this->latestVersion : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,
            'excerpt' => $this->excerpt,
            'coverImageUrl' => $this->cover_image_url,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'publishedVersionNumber' => $this->published_version_number,
            'author' => $this->whenLoaded('author', function () {
                return [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'email' => $this->author->email,
                ];
            }),
            'latestVersion' => $this->whenLoaded('latestVersion', function () {
                if (!$this->latestVersion) {
                    return null;
                }
                $this->latestVersion->loadMissing('author');

                return new BlogPostVersionResource($this->latestVersion);
            }, null),
            'publishedVersion' => $this->whenLoaded('publishedVersion', function () {
                if (!$this->publishedVersion) {
                    return null;
                }
                $this->publishedVersion->loadMissing('author');

                return new BlogPostVersionResource($this->publishedVersion);
            }, null),
            'statusEvents' => BlogPostStatusEventResource::collection($this->whenLoaded('statusEvents')),
            'latestReviewerComment' => optional($this->statusEvents
                    ?->where('new_status', 'rejected')
                ->sortByDesc('created_at')
                ->first())->reason,
            'summary' => $this->excerpt ?: ($latestVersion ? Str::limit(strip_tags($latestVersion->content), 160) : null),
        ];
    }
}
