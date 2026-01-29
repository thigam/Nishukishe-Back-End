<?php

namespace App\Policies;

use App\Models\Parcel;
use App\Models\User;
use App\Models\UserRole;

class ParcelPolicy
{
    /**
     * Determine whether the user can view a parcel.
     */
    public function view(User $user, Parcel $parcel): bool
    {
        return in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::SACCO]);
    }

    /**
     * Determine whether the user can create parcels.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::SACCO]);
    }

    /**
     * Determine whether the user can update the parcel.
     */
    public function update(User $user, Parcel $parcel): bool
    {
        return in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::SACCO]);
    }
}
