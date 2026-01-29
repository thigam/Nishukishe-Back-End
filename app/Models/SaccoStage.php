<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaccoStage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'sacco_id',
        'name',
        'description',
        'latitude',
        'longitude',
        'image_url',
        'destinations',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'destinations' => 'array',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }
}
