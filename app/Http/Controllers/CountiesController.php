<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SharedModels\Counties;
use Illuminate\Http\JsonResponse;

class CountiesController extends Controller
{
    /**
     * Display a listing of counties.
     */
    public function index(): JsonResponse
    {
        // Fetch all counties from the database
        $counties = Counties::all();
        return response()->json($counties);
    }
    /**
     * Display the specified county.
     */
    public function find($county_id): JsonResponse
    {
        // Find a specific county by its ID
        $county = counties::find($county_id);
        if ($county) {
            return response()->json($county);
        }
        return response()->json(['message' => 'County not found'], 404);
    }
}