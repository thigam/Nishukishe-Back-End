<?php

namespace App\Services\Comments;

use App\Models\Booking;
use App\Models\Sacco;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CommentEligibilityService
{
    private const CONFIRMED_STATUSES = ['confirmed', 'completed'];
    private const PAID_STATUSES = ['paid', 'settled'];

    public function userCanCommentOn(User $user, Model $subject): bool
    {
        if (! $user->getKey()) {
            return false;
        }

        return match (true) {
            $subject instanceof TourEvent => $this->hasBookingForTour($user, $subject),
            default => false,
        };
    }

    protected function hasBookingForSacco(User $user, Sacco $sacco): bool
    {
        return $this->bookingBaseQuery($user)
            ->whereHas('bookable', function (Builder $query) use ($sacco): void {
                $query->where('sacco_id', $sacco->sacco_id);
            })
            ->exists();
    }

    protected function hasBookingForOperator(User $user, TembeaOperatorProfile $profile): bool
    {
        return $this->bookingBaseQuery($user)
            ->whereHas('bookable', function (Builder $query) use ($profile): void {
                $query->where('organizer_id', $profile->user_id);
            })
            ->exists();
    }

    protected function hasBookingForTour(User $user, TourEvent $tourEvent): bool
    {
        return $this->bookingBaseQuery($user)
            ->where('bookable_id', $tourEvent->bookable_id)
            ->exists();
    }

    protected function bookingBaseQuery(User $user): Builder
    {
        return Booking::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $query): void {
                $query->whereIn('status', self::CONFIRMED_STATUSES)
                    ->orWhereIn('payment_status', self::PAID_STATUSES);
            });
    }
}
