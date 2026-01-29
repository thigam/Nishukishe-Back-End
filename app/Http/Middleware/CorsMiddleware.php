<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
 
        $origin = $request->headers->get('Origin');
        
        // Log for debugging
        \Log::info('CORS Middleware - Method: ' . $request->method() . ', Origin: ' . $origin);
        
        // Define allowed origins explicitly
	$allowedOrigins = [
	    'https://frontend.nishy.test',
        'http://nishukishe.com',
        'https://nishukishe.com',
        'https://backend.moskwito.com',
        'https://front.moskwito.com',
        'https://images.nishukishe.com',
        ];
        
        // Check if the origin is allowed
        $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : null;
        
        \Log::info('CORS Middleware - Allowed Origin: ' . ($allowOrigin ?: 'NONE'));
        
        // Handle OPTIONS preflight request
        if ($request->isMethod('OPTIONS')) {
            $headers = [
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, Accept',
                'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After',
                'Access-Control-Max-Age' => '86400',
            ];
            
            if ($allowOrigin) {
                $headers['Access-Control-Allow-Origin'] = $allowOrigin;
                $headers['Access-Control-Allow-Credentials'] = 'true';
                $headers['Vary'] = 'Origin';
            }
            
            \Log::info('CORS Middleware - OPTIONS response headers:', $headers);
            return response()->json([], 200, $headers);
        }
        
        // Handle the normal request
        $response = $next($request);
        
        \Log::info('CORS Middleware - Response status: ' . $response->getStatusCode());
        
        // Always set CORS headers for allowed origins, regardless of response status
        if ($allowOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, Accept');
            $response->headers->set('Access-Control-Expose-Headers', 'X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After');
            $response->headers->set('Vary', 'Origin');
            
            \Log::info('CORS Middleware - Set CORS headers for origin: ' . $allowOrigin);
        }
        
        \Log::info('CORS Middleware - Final response headers:', $response->headers->all());
        
        return $response;
    }
}
