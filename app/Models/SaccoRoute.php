<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoRoute extends Model
{
    use HasFactory;

    protected $table = 'sacco_routes';
    protected $primaryKey = 'sacco_route_id';
    public $incrementing = false; // Assuming it's not auto-incrementing if it's a custom ID
    protected $keyType = 'string'; // Assuming string based on typical custom IDs, verify with tinker

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
