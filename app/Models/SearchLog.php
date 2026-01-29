<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'query',
        'has_result',
        'source',
        'origin_slug',
        'destination_slug',
        'origin_lat',
        'origin_lng',
        'destination_lat',
        'destination_lng',
    ];

    protected $casts = [
        'query' => 'array',
        'has_result' => 'boolean',
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'destination_lat' => 'float',
        'destination_lng' => 'float',
    ];
}
