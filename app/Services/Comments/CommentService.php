<?php

namespace App\Services\Comments;

use App\Models\ActivityLog;
use App\Models\Bookable;
use App\Models\Comment;
use App\Models\Sacco;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use App\Models\Incident;
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
            'sacco', 'saccos' => Sacco::resolveByIdOrSlug($identifier) ?? throw (new ModelNotFoundException())->setModel(Sacco::class, [$identifier]),
            'operator', 'operators' => TembeaOperatorProfile::where('slug', $identifier)
                ->orWhere('id', $identifier)
                ->firstOrFail(),
            'tour', 'tours' => $this->resolveTour($identifier),
            'incident', 'incidents' => Incident::findOrFail($identifier),
            default => throw (new ModelNotFoundException())->setModel(Model::class, [$identifier]),
        };
    }

    public function paginateForSubject(Model $subject, int $perPage = 10, bool $includeAll = false, ?string $status = null): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 50));

        if ($subject instanceof Incident) {
            $realComments = $subject->comments()->with('author')->latest()->get();

            $siblings = Incident::with('user')
                ->where('id', '!=', $subject->id)
                ->where('type', $subject->type)
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('reported_at', '>=', now()->subHours(6))
                            ->whereNull('end_time');
                    })
                    ->orWhere('end_time', '>=', now())
                    ->orWhere('start_time', '>=', now());
                })
                ->whereBetween('lat', [$subject->lat - 0.005, $subject->lat + 0.005])
                ->whereBetween('lng', [$subject->lng - 0.005, $subject->lng + 0.005])
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->orderBy('reported_at', 'desc')
                ->get();

            $virtualComments = collect();
            foreach ($siblings as $sibling) {
                $virtualComment = new Comment();
                $virtualComment->id = "sibling-{$sibling->id}";
                $virtualComment->body = $sibling->description;
                $virtualComment->status = Comment::STATUS_APPROVED;
                $virtualComment->created_at = $sibling->reported_at;
                $virtualComment->updated_at = $sibling->reported_at;
                $virtualComment->user_id = $sibling->user_id;

                if ($sibling->user) {
                    $virtualComment->setRelation('author', $sibling->user);
                } else {
                    $virtualComment->setRelation('author', null);
                }

                $virtualComments->push($virtualComment);
            }

            $merged = $realComments->concat($virtualComments)->sortByDesc('created_at');

            $page = request()->query('page', 1);
            $slice = $merged->slice(($page - 1) * $perPage, $perPage)->values();

            return new \Illuminate\Pagination\LengthAwarePaginator(
                $slice,
                $merged->count(),
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        $query = $subject->comments()->with('author')->latest();

        if ($includeAll && $status && in_array($status, [Comment::STATUS_PENDING, Comment::STATUS_APPROVED, Comment::STATUS_HIDDEN], true)) {
            $query->where('status', $status);
        } elseif (! $includeAll) {
            $query->where('status', Comment::STATUS_APPROVED);
        }

        return $query->paginate($perPage);
    }

    public function create(User $author, Model $subject, array $attributes): Comment
    {
        /** @var Comment $comment */
        $comment = $subject->comments()->create([
            'user_id' => $author->id,
            'body' => Arr::get($attributes, 'body'),
            'rating' => Arr::get($attributes, 'rating'),
            'status' => $subject instanceof Incident ? Comment::STATUS_APPROVED : Comment::STATUS_PENDING,
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
        if ($comment->commentable_type === Incident::class) {
            $comment->status = Comment::STATUS_APPROVED;
        } else {
            $comment->status = Comment::STATUS_PENDING;
        }
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
