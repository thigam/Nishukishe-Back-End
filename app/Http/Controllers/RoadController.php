<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoadController extends Controller
{
    public function suggest(Request $request)
    {
        $query = $request->query('query', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $roads = DB::table('roads')
            ->where('name', 'like', "%{$query}%")
            ->distinct()
            ->orderBy('name', 'asc')
            ->limit(10)
            ->pluck('name');

        return response()->json($roads);
    }
}
