<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Route as TransportRoute;

class RouteController extends Controller
{
    public function index()
    {
        return TransportRoute::select(
            'route_id',
            'route_number',
            'route_start_stop',
            'route_end_stop'
        )->orderBy('route_number')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'route_number' => 'required|string|unique:routes,route_number',
            'route_start_stop' => 'required|string',
            'route_end_stop' => 'required|string',
        ]);

        $data['route_id'] = $data['route_id'] ?? Str::uuid()->toString();

        $route = TransportRoute::create($data);

        return response()->json($route, 201);
    }
}

