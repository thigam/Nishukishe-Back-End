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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
