<?php
namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RecordSearchMetrics;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \App\Http\Middleware\LogUserActivity::class,
        RecordSearchMetrics::class,

    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
],


        'api' => [
             \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class, 
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            'throttle:60,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\CorsMiddleware::class, // Ensure CORS is applied to API routes
        ],
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'cors' => \App\Http\Middleware\CorsMiddleware::class,
        'role' => EnsureRole::class,
        'verify.sacco' => \App\Http\Middleware\VerifySaccoOwnership::class,
        'verify.tier' => \App\Http\Middleware\VerifySaccoTier::class,
    ];

    /**
     * The commands to be registered by the application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\PopulateDirections::class,
        \App\Console\Commands\BackfillH3Index::class,
        \App\Console\Commands\BackfillRouteStop::class,
        \App\Console\Commands\BackfillNearestNode::class,
        \App\Console\Commands\BuildTransferEdges::class,
    ];
}
