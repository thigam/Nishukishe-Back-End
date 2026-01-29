<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stops extends Model
{
    /**
     * no timestamps
     * @var bool
     */
    public $incrementing = false;
    protected $primaryKey = 'stop_id';
    protected $keyType     = 'string';
    public $timestamps = false;

    protected $fillable = [
        'stop_id',
        'stop_name',
        // 'stop_outbound_coordinates',
        // 'stop_inbound_coordinates',
        'stop_lat',
        'stop_long',
        'county_id',//foreign key to counties table
        'direction_id'//foreign key to directions table
    ];

    protected $casts = [
        // 'stop_outbound_coordinates' => 'json',
        // 'stop_inbound_coordinates' => 'json',
    ];
    public function county()
    {
        return $this->belongsTo(Counties::class, 'county_id', 'county_id');
    }

    public function direction()
    {
        return $this->belongsTo(Directions::class, 'direction_id', 'direction_id');
    }
    public function routes()
    {//explain this function
        // This function defines a many-to-many relationship between the Stops model and the SaccoRoutes model.
        // It uses the belongsToMany method to establish the relationship through a pivot table named 'route_stop'.
        // The 'stop_id' column in the pivot table is used to link to the Stops model,
        // and the 'route_id' column is used to link to the SaccoRoutes model.
        return $this->belongsToMany(SaccoRoutes::class, 'route_stop', 'stop_id', 'route_id');
    }
    
}
