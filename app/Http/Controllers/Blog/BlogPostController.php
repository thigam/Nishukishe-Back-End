<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use App\Models\BlogPostStatusEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogPostController extends Controller
{
    public function mine(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', BlogPost::class);

        $posts = BlogPost::query()
            ->with([
                'latestVersion.author',
                'statusEvents' => fn ($query) => $query->latest(),
                'statusEvents.actor',
            ])
            ->where('author_id', $user->id)
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 10));

        return BlogPostResource::collection($posts);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BlogPost::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'excerpt' => ['nullable', 'string', 'max:600'],
            'coverImageUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $post = BlogPost::create([
            'author_id' => $request->user()->id,
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'] ?? null,
            'cover_image_url' => $validated['coverImageUrl'] ?? null,
            'status' => BlogPost::STATUS_DRAFT,
        ]);

        $version = $post->createVersion([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'editor_notes' => null,
            'created_by' => $request->user()->id,
        ]);

        $post->load(['latestVersion.author', 'statusEvents', 'statusEvents.actor']);

        return (new BlogPostResource($post))->additional([
            'version' => $version->version_number,
        ])->response()->setStatusCode(201);
    }

    public function show(Request $request, BlogPost $blogPost)
    {
        $this->authorize('view', $blogPost);

        $blogPost->load([
            'author',
            'latestVersion.author',
            'publishedVersion.author',
            'statusEvents' => fn ($query) => $query->latest(),
            'statusEvents.actor',
        ]);

        return new BlogPostResource($blogPost);
    }

    public function update(Request $request, BlogPost $blogPost)
    {
        $this->authorize('update', $blogPost);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'excerpt' => ['nullable', 'string', 'max:600'],
            'coverImageUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        if (array_key_exists('title', $validated)) {
            $blogPost->title = $validated['title'];
        }
        if (array_key_exists('excerpt', $validated)) {
            $blogPost->excerpt = $validated['excerpt'];
        }
        if (array_key_exists('coverImageUrl', $validated)) {
            $blogPost->cover_image_url = $validated['coverImageUrl'];
        }

        if (array_key_exists('content', $validated) || array_key_exists('title', $validated)) {
            $content = $validated['content'] ?? $blogPost->latestVersion?->content ?? '';
            $title = $validated['title'] ?? $blogPost->latestVersion?->title ?? $blogPost->title;

            $blogPost->createVersion([
                'title' => $title,
                'content' => $content,
                'editor_notes' => null,
                'created_by' => $request->user()->id,
            ]);
        }

        $blogPost->save();

        $blogPost->refresh()->load([
            'latestVersion.author',
            'statusEvents' => fn ($query) => $query->latest(),
            'statusEvents.actor',
        ]);

        return new BlogPostResource($blogPost);
    }

    public function submit(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->authorize('submit', $blogPost);

        $note = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ])['note'] ?? null;

        $oldStatus = $blogPost->status;
        $blogPost->status = BlogPost::STATUS_PENDING;
        $blogPost->save();

        BlogPostStatusEvent::create([
            'blog_post_id' => $blogPost->id,
            'old_status' => $oldStatus,
            'new_status' => BlogPost::STATUS_PENDING,
            'changed_by' => $request->user()->id,
            'reason' => $note,
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function revert(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->authorize('revert', $blogPost);

        $oldStatus = $blogPost->status;
        $blogPost->status = BlogPost::STATUS_DRAFT;
        $blogPost->save();

        $latestVersion = $blogPost->latestVersion;
        if ($latestVersion) {
            $blogPost->createVersion([
                'title' => $latestVersion->title,
                'content' => $latestVersion->content,
                'editor_notes' => $latestVersion->editor_notes,
                'created_by' => $request->user()->id,
            ]);
        }

        BlogPostStatusEvent::create([
            'blog_post_id' => $blogPost->id,
            'old_status' => $oldStatus,
            'new_status' => BlogPost::STATUS_DRAFT,
            'changed_by' => $request->user()->id,
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, BlogPost $blogPost)
    {
        $this->authorize('delete', $blogPost);

        $blogPost->delete();

        return response()->noContent();
    }
}
