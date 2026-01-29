<?php

namespace App\Models\SharedModels;

use Illuminate\Database\Eloquent\Model;

class Counties extends Model
{
    /**
     * no timestamps
     * @var bool
     */
    public $timestamps = false;
    protected $fillable = [
        'county_id',
        'county_name',
        'county_hq'
    ];

    protected $primaryKey = 'county_id';
    protected $keyType = 'string';
    public $incrementing = false;
}
