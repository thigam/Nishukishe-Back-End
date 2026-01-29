<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchMetric extends Model
{
    protected $fillable = [
        'sacco_id',
        'sacco_route_id',
        'rank',
    ];
}
