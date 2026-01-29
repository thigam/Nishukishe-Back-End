<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use App\Models\BlogPostStatusEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SuperAdminBlogController extends Controller
{
    public function pending(Request $request)
    {
        $this->authorize('viewAny', BlogPost::class);

        $posts = BlogPost::query()
            ->with([
                'author',
                'latestVersion.author',
                'statusEvents' => fn ($query) => $query->latest(),
            ])
            ->where('status', BlogPost::STATUS_PENDING)
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 10));

        return BlogPostResource::collection($posts);
    }

    public function approve(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->authorize('approve', $blogPost);

        $blogPost->loadMissing('latestVersion');

        $blogPost->status = BlogPost::STATUS_PUBLISHED;
        $blogPost->published_at = Carbon::now();
        $blogPost->approved_by = $request->user()->id;
        $blogPost->slug = BlogPost::generateUniqueSlug($blogPost->title, $blogPost->id);

        if ($blogPost->latestVersion) {
            $blogPost->markPublishedVersion($blogPost->latestVersion);
            if (empty($blogPost->excerpt)) {
                $blogPost->excerpt = Str::limit(strip_tags($blogPost->latestVersion->content), 220);
            }
        }

        $oldStatus = $blogPost->getOriginal('status');
        $blogPost->save();

        BlogPostStatusEvent::create([
            'blog_post_id' => $blogPost->id,
            'old_status' => $oldStatus,
            'new_status' => BlogPost::STATUS_PUBLISHED,
            'changed_by' => $request->user()->id,
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function reject(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->authorize('reject', $blogPost);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldStatus = $blogPost->status;
        $blogPost->status = BlogPost::STATUS_REJECTED;
        $blogPost->approved_by = null;
        $blogPost->published_at = null;
        $blogPost->save();

        BlogPostStatusEvent::create([
            'blog_post_id' => $blogPost->id,
            'old_status' => $oldStatus,
            'new_status' => BlogPost::STATUS_REJECTED,
            'changed_by' => $request->user()->id,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function archive(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->authorize('archive', $blogPost);

        $oldStatus = $blogPost->status;
        $blogPost->status = BlogPost::STATUS_ARCHIVED;
        $blogPost->save();

        BlogPostStatusEvent::create([
            'blog_post_id' => $blogPost->id,
            'old_status' => $oldStatus,
            'new_status' => BlogPost::STATUS_ARCHIVED,
            'changed_by' => $request->user()->id,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
