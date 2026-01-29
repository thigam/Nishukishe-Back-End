<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Events\Approved;
use Illuminate\Support\Facades\Log;
use App\Events\UserApproved;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Comment;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'is_verified',
        'is_active',
        'is_approved', // for sacco managers
        'google_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'is_approved' => 'boolean',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Boot method to attach model events
     */
    protected static function booted()
    {
        static::updated(function ($user) {
            // Fire event only when user becomes approved
            if ($user->is_approved && $user->wasChanged('is_approved')) {
                event(new UserApproved($user));
                Log::info("Approved event dispatched for User: {$user->email}");
            }
        });
    }

    public function tembeaOperatorProfile(): HasOne
    {
        return $this->hasOne(TembeaOperatorProfile::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

}
