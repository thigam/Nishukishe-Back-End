<?php

use App\Http\Controllers\SaccosController;
use App\Models\Sacco;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Middleware\CorsMiddleware;
use App\Models\Route as TransportRoute;
use Jenssegers\Agent\Agent;

require __DIR__ . '/authentication.php';
require __DIR__ . '/SaccoRoutes.php';
require __DIR__ . '/RouteRoutes.php';
require __DIR__ . '/DirectionRoutes.php';
require __DIR__ . '/StopRoute.php';
require __DIR__ . '/CountyRoutes.php';
require __DIR__ . '/PreCleanRoutes.php';
require __DIR__ . '/PreCleanRoutes.php';
require __DIR__ . '/PostCleanRoutes.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/VariationRoutes.php';
require __DIR__ . '/superadmin.php';

Route::get('admin/search-analytics', [\App\Http\Controllers\SearchAnalyticsController::class, 'index']);


Route::get('/', function (): JsonResponse {
    return response()->json([
        'message' => 'Welcome to the home page',
        'status' => 200,
    ]);

})->name('home');

//Route::get('/TransportRoute', function () {
//  return Route::all(['route_id', 'route_number', 'route_start_stop', 'route_end_stop']);
//});
// Explicitly handle OPTIONS requests for all  routes

Route::get('/bladeview', function () {
    return view('bladeview', [
        'user' => 'user',
        'verificationUrl' => 'https://example.com/verify-email',
    ]);
})->name('bladeview');


Route::options('{any}', function (Request $request) {
    $origin = $request->headers->get('Origin');
    $allowedOrigins = [
        'https://frontend.nishy.test',
        'http://nishukishe.com',
        'https://nishukishe.com',
    ];

    if (in_array($origin, $allowedOrigins)) {
        return response()->json([], 200, [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, Accept',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ]);
    }

    return response()->json(["ready to take off"], 200);
})->where('any', '.*');
