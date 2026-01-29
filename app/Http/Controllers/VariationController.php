<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Variation;

class VariationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'sacco_route_id' => 'required|string',
            'coordinates'    => 'array',
            'stop_ids'       => 'array',
        ]);

        $variation = Variation::create($data);
        return response()->json($variation, 201);
    }
}
