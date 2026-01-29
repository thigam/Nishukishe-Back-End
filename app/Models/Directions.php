<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Directions extends Model
{
    /**
     * no timestamps
     * @var bool
     */
    public $timestamps = false;

    protected $primaryKey = 'direction_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'direction_id',
        'direction_heading',
        'direction_latitude',
        'direction_longitude',
        'direction_routes',
        'direction_ending',
        'h3_index',
        'nearest_node_id',
        'nearest_node_ids',
    ];

    protected $casts = [
        'direction_heading' => 'string',
        'direction_routes' => 'array',
        'direction_ending' => 'array',
        'nearest_node_id'  => 'integer',
        'nearest_node_ids' => 'array',
        'direction_latitude'  => 'float',
        'direction_longitude' => 'float',
    ];
    /**
     * Define the relationship with the Route model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    
        public function routes()
    {  //explain this function

        // This function defines a many-to-many relationship between the Directions model and the Route model.
        // It uses the belongsToMany method to establish the relationship through a pivot table named 'sacco_routes'.
        // The 'direction_id' column in the pivot table is used to link to the Directions model,
        // and the 'route_id' column is used to link to the Route model.
        // This allows you to access all the routes associated with a specific direction.
        // The belongsToMany method returns a collection of Route models that are associated with the current Direction model instance.
        return $this->belongsToMany(Route::class, 'sacco_routes', 'direction_id', 'route_id');
	}
   // Directions.php
public function stop()
{
    return $this->hasOne(Stops::class, 'stop_id', 'direction_id');
}


}
