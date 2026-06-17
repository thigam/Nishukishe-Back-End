<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TrustCloudflareHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->hasHeader('CF-Connecting-IP')) {
            $cfIp = $request->header('CF-Connecting-IP');
            $request->server->set('REMOTE_ADDR', $cfIp);
            $request->headers->set('X-Forwarded-For', $cfIp);
        }

        return $next($request);
    }
}
