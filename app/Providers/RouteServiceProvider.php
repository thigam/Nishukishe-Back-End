<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your “home” route for your application.
     *
     * Typically users are redirected here after login.
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // Stateless API routes, no CSRF
            Route::middleware('api')
                 ->prefix('api')
                 ->group(base_path('routes/api.php'));

            // Web routes with session & CSRF protection
            Route::middleware('web')
                 ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // e.g. 60 requests per minute per user/IP
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('multileg-route', function (Request $request) {
            $key = sprintf('multileg-route|%s', $request->user()?->id ?? $request->ip());

            return Limit::perMinute(20)->by($key)->response(function (Request $request, array $headers) {
                $payload = [
                    'message' => 'Too many multileg route requests. Please retry after the cooldown.',
                    'hint'    => 'You can slow down requests or back off when rate-limit headers signal exhaustion.',
                ];

                \Log::warning('Multileg route rate limit hit', [
                    'ip'      => $request->ip(),
                    'user_id' => $request->user()?->id,
                    'path'    => $request->path(),
                ]);

                return response()->json($payload, 429, $headers);
            });
        });
    }
}

