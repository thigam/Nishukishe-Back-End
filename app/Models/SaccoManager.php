<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoManager extends Model
{
    protected $fillable = [
        'user_id',
        'sacco_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }
}
