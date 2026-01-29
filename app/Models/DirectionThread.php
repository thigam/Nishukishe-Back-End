<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Comment;

class DirectionThread extends Model
{
    protected $fillable = ['origin_slug', 'destination_slug', 'origin_stop_id', 'destination_stop_id'];

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
