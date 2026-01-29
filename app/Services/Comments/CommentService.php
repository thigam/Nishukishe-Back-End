<?php

namespace App\Services\Comments;

use App\Models\ActivityLog;
use App\Models\Bookable;
use App\Models\Comment;
use App\Models\Sacco;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;

class CommentService
{
    public function __construct(private CommentNotificationService $notificationService)
    {
    }

    public function resolveSubject(string $type, string $identifier): Model
    {
        $normalized = strtolower($type);

        return match ($normalized) {
            'sacco', 'saccos' => Sacco::where('sacco_id', $identifier)->firstOrFail(),
            'operator', 'operators' => TembeaOperatorProfile::where('slug', $identifier)
                ->orWhere('id', $identifier)
                ->firstOrFail(),
            'tour', 'tours' => $this->resolveTour($identifier),
            default => throw (new ModelNotFoundException())->setModel(Model::class, [$identifier]),
        };
    }

    public function paginateForSubject(Model $subject, int $perPage = 10, bool $includeAll = false, ?string $status = null): LengthAwarePaginator
    {
        $query = $subject->comments()->with('author')->latest();

        if ($includeAll && $status && in_array($status, [Comment::STATUS_PENDING, Comment::STATUS_APPROVED, Comment::STATUS_HIDDEN], true)) {
            $query->where('status', $status);
        } elseif (! $includeAll) {
            $query->where('status', Comment::STATUS_APPROVED);
        }

        $perPage = max(1, min($perPage, 50));

        return $query->paginate($perPage);
    }

    public function create(User $author, Model $subject, array $attributes): Comment
    {
        /** @var Comment $comment */
        $comment = $subject->comments()->create([
            'user_id' => $author->id,
            'body' => Arr::get($attributes, 'body'),
            'rating' => Arr::get($attributes, 'rating'),
            'status' => Comment::STATUS_PENDING,
        ]);

        $comment->load(['author']);
        $this->recordActivity($author, $comment, 'created');
        $this->notificationService->notify($comment);

        return $comment;
    }

    public function update(Comment $comment, array $attributes, User $actor): Comment
    {
        $comment->fill([
            'body' => Arr::get($attributes, 'body', $comment->body),
            'rating' => Arr::get($attributes, 'rating', $comment->rating),
        ]);
        $comment->status = Comment::STATUS_PENDING;
        $comment->save();

        $comment->refresh()->load('author');
        $this->recordActivity($actor, $comment, 'updated');

        return $comment;
    }

    public function delete(Comment $comment, User $actor): void
    {
        $comment->delete();
        $this->recordActivity($actor, $comment, 'deleted');
    }

    public function moderate(Comment $comment, string $status, User $moderator): Comment
    {
        $comment->status = $status;
        $comment->save();

        $comment->refresh()->load('author');
        $this->recordActivity($moderator, $comment, 'moderated');

        return $comment;
    }

    protected function resolveTour(string $identifier): TourEvent
    {
        $bookable = Bookable::where('slug', $identifier)->orWhere('id', $identifier)->first();

        if (! $bookable) {
            throw (new ModelNotFoundException())->setModel(TourEvent::class, [$identifier]);
        }

        $tour = $bookable->tourEvent;

        if (! $tour) {
            throw (new ModelNotFoundException())->setModel(TourEvent::class, [$identifier]);
        }

        return $tour;
    }

    protected function recordActivity(?User $actor, Comment $comment, string $action): void
    {
        ActivityLog::create([
            'user_id' => $actor?->id,
            'session_id' => request()?->hasSession() ? request()->session()->getId() : null,
            'ip_address' => request()?->ip(),
            'device' => 'api',
            'browser' => 'comments',
            'urls_visited' => [
                [
                    'action' => $action,
                    'comment_id' => $comment->id,
                    'subject' => class_basename($comment->commentable_type),
                ],
            ],
            'routes_searched' => [
                [
                    'subject_id' => $comment->commentable_id,
                    'status' => $comment->status,
                ],
            ],
            'started_at' => now(),
            'ended_at' => now(),
            'duration_seconds' => 0,
        ]);
    }
}
