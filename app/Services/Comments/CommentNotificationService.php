<?php

namespace App\Services\Comments;

use App\Models\Comment;
use App\Models\Sacco;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Notifications\CommentLeftNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class CommentNotificationService
{
    public function notify(Comment $comment): void
    {
        $comment->loadMissing(['author', 'commentable']);

        $recipients = $this->resolveRecipients($comment);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CommentLeftNotification($comment));
    }

    protected function resolveRecipients(Comment $comment): Collection
    {
        $subject = $comment->commentable;

        if ($subject instanceof Sacco) {
            return $subject->managers()
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
                ->unique('id')
                ->values();
        }

        if ($subject instanceof TembeaOperatorProfile) {
            return $subject->user ? collect([$subject->user]) : collect();
        }

        if ($subject instanceof TourEvent) {
            $subject->loadMissing('bookable.organizer');
            $organizer = $subject->bookable?->organizer;

            return $organizer ? collect([$organizer]) : collect();
        }

        return collect();
    }
}
