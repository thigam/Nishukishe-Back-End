<?php

// app/Http/Controllers/MapController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http; // For making HTTP requests

class MapsController extends Controller
{
    // Fetch location data from Google Maps API
    public function getMapsJs(Request $request)
{   //https://maps.googleapis.com/maps/api/js?key=${apiKey}&callback=${callback}&libraries=marker,places
    $googleMapsApiKey = env('GOOGLE_MAPS_API_KEY', env('NEXT_PUBLIC_GOOGLE_PLACES_KEY'));
    $url = "https://maps.googleapis.com/maps/api/js";
    $params = [
        'key' => $googleMapsApiKey,
        'callback' => 'initMap',
        'libraries' => 'marker,places',
        'loading' => 'async'
    ];

    $response = Http::get($url, $params);

    if ($response->successful()) {
        return response($response->body(), 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    } else {
        return response()->json(['error' => 'Failed to fetch Google Maps JS'], 500)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }
    }
    public function autocomplete(Request $request)
    {
        $input = $request->query('input');
        if (!$input) {
            return response()->json(['error' => 'Missing input'], 400);
        }

        $key = env('GOOGLE_MAPS_API_KEY', env('NEXT_PUBLIC_GOOGLE_PLACES_KEY'));
        $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
        $params = [
            'input' => $input,
            'key' => $key,
            'components' => 'country:ke|country:ug|country:tz',
        ];

        $response = Http::get($url, $params);
        return response()->json($response->json(), $response->status());
    }

    public function placeDetails(Request $request)
    {
        $placeId = $request->query('place_id');
        if (!$placeId) {
            return response()->json(['error' => 'Missing place_id'], 400);
        }

        $key = env('GOOGLE_MAPS_API_KEY', env('NEXT_PUBLIC_GOOGLE_PLACES_KEY'));
        $url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $params = [
            'place_id' => $placeId,
            'key' => $key,
        ];

        $response = Http::get($url, $params);
        return response()->json($response->json(), $response->status());
    }
}
