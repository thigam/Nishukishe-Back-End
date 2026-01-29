<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Http\Request;

class PublicBlogController extends Controller
{
    public function index(Request $request)
    {
        $posts = BlogPost::query()
            ->with(['publishedVersion.author', 'author'])
            ->where('status', BlogPost::STATUS_PUBLISHED)
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($subQuery) use ($request) {
                    $subQuery
                        ->where('title', 'like', '%'.$request->query('search').'%')
                        ->orWhere('excerpt', 'like', '%'.$request->query('search').'%');
                });
            })
            ->orderByDesc('published_at')
            ->paginate($request->integer('per_page', 10));

        return BlogPostResource::collection($posts);
    }

    public function show(string $slug)
    {
        $post = BlogPost::query()
            ->with(['publishedVersion.author', 'author'])
            ->where('slug', $slug)
            ->where('status', BlogPost::STATUS_PUBLISHED)
            ->firstOrFail();

        return new BlogPostResource($post);
    }
}
