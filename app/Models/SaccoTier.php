<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoTier extends Model
{
    protected $fillable = [
        'name',
        'price',
        'features',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'features' => 'array',
        'is_active' => 'boolean',
    ];
}
