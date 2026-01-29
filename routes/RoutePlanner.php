<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RoutePlannerController;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;

//group routes under 'api' prefix
// Route::prefix('api')->middleware(CorsMiddleware::class)->group(function () {
//     // Define the multileg route endpoint
//     Route::post('/multileg-route', [RoutePlannerController::class, 'multilegRoute'])
//         ->name('api.multileg-route');
//     Route::get('/multileg-route', [RoutePlannerController::class, 'index'])
//         ->name('api.multileg-route.get');
// });


//     // Explicitly handle OPTIONS requests for all  routes
// Route::options('api/multileg-route', function (Request $request) {
//     $origin = $request->headers->get('Origin');
//     $allowedOrigins = [
//         'http://192.168.100.15:8000',
//         'http://localhost:8000',
//         'http://127.0.0.1:8000',
//         'http://moskwito.com',
//         'https://moskwito.com',
//         'http://frontend.nishy.test',
//         'https://frontend.nishy.test',
//         'http://frontend.moskwito.com',
//         'https://frontend.moskwito.com',
//     ];
    
//     if (in_array($origin, $allowedOrigins)) {
//         return response()->json([], 200, [
//             'Access-Control-Allow-Origin' => $origin,
//             'Access-Control-Allow-Credentials' => 'true',
//             'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
//             'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, Accept',
//             'Access-Control-Max-Age' => '86400',
//             'Vary' => 'Origin',
//         ]);
//     }
    
//     return response()->json(["ready to take off"], 200);
// })->where('any', '.*');