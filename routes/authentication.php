<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Http\Request;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Support\Facades\Password;
use App\Events\PasswordResetLinkSent;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\LogUserActivity;


// Apply CORS middleware to all auth routes

Route::middleware([CorsMiddleware::class, LogUserActivity::class])->prefix('auth')->group(function () {

    Route::controller(AuthController::class)->group(function () {
        Route::middleware('auth:sanctum')->get('/user', 'getUser')->name('auth.user');
        Route::post('/login', 'login')->name('login');
        Route::post('/register', 'register')->name('register');
        Route::get('/logout', 'logout')->name('logout');

        Route::get('/google/redirect', 'redirectToGoogle')->name('auth.google.redirect');
        Route::get('/google/callback', 'handleGoogleCallback')->name('auth.google.callback');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/google/link', 'redirectToGoogleForLinking')->name('auth.google.link');
            Route::delete('/google/link', 'unlinkGoogle')->name('auth.google.unlink');
            Route::get('/google/status', 'googleStatus')->name('auth.google.status');
        });

        Route::post('sacco-manager/signup', 'saccoAdminSignup')
            ->name('sacco-manager.signup');

        Route::get('/approve/{id}', 'approveUser')
            ->name('approve.user');

        Route::put('/update-profile', 'updateProfile')
            ->name('update.profile');
    });

    Route::get('/forgot-password/{email}', [PasswordResetController::class, 'forgot'])
        ->name('password.forgot');

    // Email verification routes
    Route::get('/email/verify/{id}/{hash}', [PasswordResetController::class, 'verify'])
        ->middleware(['signed', CorsMiddleware::class, LogUserActivity::class])
        ->name('verification.verify');



});

// Reset password
Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->name('password.reset')
    ->middleware([CorsMiddleware::class, LogUserActivity::class]);


Route::post('/email/verification-notification', [PasswordResetController::class, 'sendNotification'])
    ->middleware(['auth', 'throttle:6,1', CorsMiddleware::class, LogUserActivity::class])
    ->name('verification.send');

Route::get('/email/verify/{id}', [PasswordResetController::class, 'notice'])
    ->name('verification.notice')
    ->middleware([CorsMiddleware::class, LogUserActivity::class]);


// Explicitly handle OPTIONS requests for all  routes
Route::options('{any}', function (Request $request) {
    $origin = $request->headers->get('Origin');
    $allowedOrigins = [
        'http://nishukishe.com',
        'https://nishukishe.com',
        'http://dev.nishukishe.com',
        'https://dev.nishukishe.com',
        'http://localhost:3000',
        'https://frontend.nishy.test',


    ];

    if (in_array($origin, $allowedOrigins)) {
        \Log::info('CORS Middleware - OPTIONS request from allowed origin: ' . $origin);
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




