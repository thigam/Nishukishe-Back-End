<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'name',
        'content_html',
        'design_json',
        'is_active',
    ];

    protected $casts = [
        'design_json' => 'array',
        'is_active' => 'boolean',
    ];
}
