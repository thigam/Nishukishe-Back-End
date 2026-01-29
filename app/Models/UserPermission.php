<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'permission'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    //
}
