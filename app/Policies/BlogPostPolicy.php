<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;
use App\Models\UserRole;

class BlogPostPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::SERVICE_PERSON, UserRole::SUPER_ADMIN], true);
    }

    public function view(User $user, BlogPost $blogPost): bool
    {
        if ($user->role === UserRole::SUPER_ADMIN) {
            return true;
        }

        if ($blogPost->status === BlogPost::STATUS_PUBLISHED) {
            return true;
        }

        if ($user->role === UserRole::SERVICE_PERSON) {
            return $blogPost->author_id === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SERVICE_PERSON && $user->is_approved;
    }

    public function update(User $user, BlogPost $blogPost): bool
    {
        return $user->role === UserRole::SERVICE_PERSON
            && $blogPost->author_id === $user->id
            && $blogPost->status === BlogPost::STATUS_DRAFT;
    }

    public function submit(User $user, BlogPost $blogPost): bool
    {
        return $user->role === UserRole::SERVICE_PERSON
            && $blogPost->author_id === $user->id
            && in_array($blogPost->status, [BlogPost::STATUS_DRAFT, BlogPost::STATUS_REJECTED], true);
    }

    public function revert(User $user, BlogPost $blogPost): bool
    {
        return $user->role === UserRole::SERVICE_PERSON
            && $blogPost->author_id === $user->id
            && in_array($blogPost->status, [BlogPost::STATUS_PENDING, BlogPost::STATUS_REJECTED], true);
    }

    public function approve(User $user, BlogPost $blogPost): bool
    {
        return $user->role === UserRole::SUPER_ADMIN
            && $blogPost->status === BlogPost::STATUS_PENDING;
    }

    public function reject(User $user, BlogPost $blogPost): bool
    {
        return $user->role === UserRole::SUPER_ADMIN
            && $blogPost->status === BlogPost::STATUS_PENDING;
    }

    public function archive(User $user, BlogPost $blogPost): bool
    {
        return $user->role === UserRole::SUPER_ADMIN
            && in_array($blogPost->status, [BlogPost::STATUS_PUBLISHED], true);
    }

    public function delete(User $user, BlogPost $blogPost): bool
    {
        if ($user->role === UserRole::SUPER_ADMIN) {
            return true;
        }

        return $user->role === UserRole::SERVICE_PERSON
            && $blogPost->author_id === $user->id;
    }
}
