<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActionLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_role',
        'action',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
