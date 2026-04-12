<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParcelAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'sacco_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }
}
