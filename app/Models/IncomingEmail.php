<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingEmail extends Model
{
    protected $table = 'incoming_emails';

    protected $fillable = [
        'user_id',
        'email',
        'subject',
        'body',
        'sender',
        'recipient',
        'received_at',
        'is_read',
        'is_spam',
        'attachments',    
    ];

    public $timestamps = false;

    /**
     * Get the sender's email address.
     */
    public function getSenderEmailAttribute()
    {
        return $this->sender;
    }

    /**
     * Get the recipient's email address.
     */
    public function getRecipientEmailAttribute()
    {
        return $this->recipient;
    }
}
