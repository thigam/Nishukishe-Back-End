<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'email',
        'token',
        'role',
        'sacco_id',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
