<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommentLeftNotification extends Notification
{
    use Queueable;

    public function __construct(private Comment $comment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $comment = $this->comment->loadMissing('commentable');
        $subject = $comment->commentable;
        $label = $subject ? Str::of(class_basename($comment->commentable_type ?? 'experience'))
            ->kebab()
            ->replace('-', ' ')
            ->title()
            ->value() : 'experience';

        $dashboardPath = match (true) {
            $subject instanceof TembeaOperatorProfile => '/tembea/admin',
            $subject instanceof TourEvent => '/tembea/admin',
            default => '/saccos/dashboard',
        };

        $actionUrl = url($dashboardPath);

        return (new MailMessage())
            ->subject('New commuter feedback awaiting review')
            ->greeting('Hello!')
            ->line('A commuter just shared feedback on your '.$label.'.')
            ->line('"'.Str::limit($comment->body, 200).'"')
            ->line('Current status: '.Str::title($comment->status))
            ->action('Review comment', $actionUrl)
            ->line('Log in to moderate comments and keep conversations healthy.');
    }
}
