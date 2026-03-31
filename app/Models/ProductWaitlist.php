<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductWaitlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'sacco_id',
        'user_id',
        'product_slug',
        'contact_name',
        'contact_phone',
        'contact_email',
        'notes',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
