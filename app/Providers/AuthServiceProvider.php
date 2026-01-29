<?php

namespace App\Providers;

use App\Models\BlogPost;
use App\Models\Comment;
use App\Models\Parcel;
use App\Policies\BlogPostPolicy;
use App\Policies\CommentPolicy;
use App\Policies\ParcelPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Parcel::class => ParcelPolicy::class,
        BlogPost::class => BlogPostPolicy::class,
        Comment::class => CommentPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
