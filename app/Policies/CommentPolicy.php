<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Comments\CommentEligibilityService;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class CommentPolicy
{
    use HandlesAuthorization;

    public function __construct(private CommentEligibilityService $eligibility)
    {
    }

    public function viewAny(?User $user, Model $subject): bool
    {
        return (bool) $subject;
    }

    public function create(User $user, Model $subject): Response|bool
    {
        if ($subject instanceof Sacco || $subject instanceof TembeaOperatorProfile) {
            return (bool) $user->getKey();
        }

        if ($this->eligibility->userCanCommentOn($user, $subject)) {
            return true;
        }

        if ($this->userCanModerateSubject($user, $subject)) {
            return true;
        }

        if ($subject instanceof TourEvent) {
            return $this->deny('You need a confirmed or paid booking to leave a comment on this tour.');
        }

        return $this->deny('You are not authorized to leave a comment for this subject.');
    }

    public function update(User $user, Comment $comment): bool
    {
        return $user->id === $comment->user_id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $user->id === $comment->user_id || $this->userCanModerateSubject($user, $comment->commentable);
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return $this->userCanModerateSubject($user, $comment->commentable);
    }

    public function moderateSubject(User $user, Model $subject): bool
    {
        return $this->userCanModerateSubject($user, $subject);
    }

    protected function userCanModerateSubject(User $user, mixed $subject): bool
    {
        if (!$subject) {
            return false;
        }

        if (in_array($user->role, [UserRole::SUPER_ADMIN], true)) {
            return true;
        }

        if ($subject instanceof Sacco) {
            return $subject->managers()->where('user_id', $user->id)->exists();
        }

        if ($subject instanceof TembeaOperatorProfile) {
            if ($subject->user_id && $subject->user_id === $user->id) {
                return true;
            }

            return $user->role === UserRole::TEMBEA;
        }

        if ($subject instanceof TourEvent) {
            $bookable = $subject->bookable;

            if ($bookable && $bookable->organizer_id === $user->id) {
                return true;
            }

            if ($bookable && $bookable->sacco_id) {
                return SaccoManager::query()
                    ->where('sacco_id', $bookable->sacco_id)
                    ->where('user_id', $user->id)
                    ->exists();
            }

            return $user->role === UserRole::TEMBEA;
        }

        return false;
    }
}
