<?php

// routes/api.php
use App\Http\Controllers\MapsController;

Route::get('/getLocationData', [MapsController::class, 'getMapsJs'])
    ->name('get.location.data'); // Apply CORS middleware to this route

Route::get('/places/autocomplete', [MapsController::class, 'autocomplete']);
Route::get('/places/details', [MapsController::class, 'placeDetails']);
