<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    protected $fillable = [
        'uuid',
        'sender_email',
        'recipient_email',
        'subject',
        'body_html',
        'message_id',
        'type',
        'read_at',
        'user_id',
        'parent_id',
        'in_reply_to_message_id',
        'references',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /* ---- Relationships ---- */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Email::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Email::class, 'parent_id')->orderBy('created_at');
    }

    /* ---- Helpers ---- */

    /**
     * Walk up the parent chain to find the root email of this thread.
     * Used as a fallback; the controller also has an upward CTE version.
     */
    public function threadRoot(): Email
    {
        $current = $this;
        while ($current->parent_id !== null) {
            $current = $current->parent;
        }
        return $current;
    }
}
