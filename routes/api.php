<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Blog\BlogPostController;
use App\Http\Controllers\Blog\PublicBlogController;
use App\Http\Controllers\Blog\SuperAdminBlogController;
use App\Http\Controllers\RoutePlannerController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\ActivityLogPageViewController;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Http\Request;
use App\Http\Controllers\SaccoManagerController;
use App\Http\Controllers\DriverLocationController;
use App\Http\Controllers\VehicleLiveLocationController;
use App\Http\Controllers\OwnerInvitationController;
use App\Http\Controllers\ParcelServicePersonController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\ParcelController;
use App\Http\Controllers\Bookings\BookableCheckoutController;
use App\Http\Controllers\Bookings\ManagerBookableController;
use App\Http\Controllers\Bookings\PublicBookableController;
use App\Http\Controllers\Bookings\TicketDownloadController;
use App\Http\Controllers\Bookings\TicketVerificationController;
use App\Http\Controllers\SuperAdmin\SaccoManagerApprovalController;
use App\Http\Controllers\TembeaOperatorController;
use App\Http\Controllers\PublicTembeaOperatorController;
use App\Http\Controllers\JengaController;
use App\Http\Controllers\CommentController;
use App\Services\DashboardService;
use App\Http\Controllers\ProductWaitlistController;
use Jenssegers\Agent\Agent;
use App\Http\Controllers\HealthStatusController;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\PasswordResetController;
require __DIR__ . '/mpesa.php';



// No CSRF here, just the 'api' middleware—which is stateless by default
Route::post('multileg-route', [RoutePlannerController::class, 'multilegRoute'])
    ->middleware([CorsMiddleware::class, LogUserActivity::class, 'throttle:multileg-route'])
    ->name('multileg.route');

Route::post('activity/directions/page-view', [ActivityLogPageViewController::class, 'storeDirections'])
    ->middleware([CorsMiddleware::class])
    ->name('activity.directions.page-view');
Route::post('activity/discover/page-view', [ActivityLogPageViewController::class, 'storeDiscover'])
    ->middleware([CorsMiddleware::class])
    ->name('activity.discover.page-view');

Route::middleware([CorsMiddleware::class, LogUserActivity::class])->prefix('auth')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::get('/google/redirect', 'redirectToGoogle')->name('auth.google.redirect');
        Route::get('/google/callback', 'handleGoogleCallback')->name('auth.google.callback');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/google/link', 'redirectToGoogleForLinking')->name('auth.google.link');
            Route::delete('/google/link', 'unlinkGoogle')->name('auth.google.unlink');
            Route::get('/google/status', 'googleStatus')->name('auth.google.status');
        });
    });
});
Route::post('receive-email/mailgun', [\App\Http\Controllers\Webhook\MailgunController::class, 'receive'])
    ->name('receive.email')
    ->middleware([CorsMiddleware::class, LogUserActivity::class]);

Route::post('webhooks/resend/incoming', [\App\Http\Controllers\Webhook\ResendWebhookController::class, 'receive']); // Resend Webhook
// Route::post('webhooks/mailgun/incoming', [\App\Http\Controllers\Webhook\MailgunController::class, 'receive']); // Deprecated


Route::get('sacco-manager/sacco-id', [SaccoManagerController::class, 'getSaccoId'])
    ->middleware([CorsMiddleware::class, LogUserActivity::class]);


// routes/web.php or routes/api.php
Route::get('/deploy/{token}', function ($token) {
    if ($token !== env('DEPLOY_TOKEN')) {
        return response()->json(['error' => 'Unauthorized!'], 401);
    }

    $output = null;
    $returnVar = null;

    exec('/home/xiaomi14/.deploy.sh > /home/xiaomi14/.deploy.log 2>&1 &');

    return response()->json([
        'status' => 'started',
        'message' => 'Deployment started in background'
    ]);
});

Route::get('/deploy_logs', function () {
    $logFile = '/home/xiaomi14/.deploy.log';
    $lastLine = '';

    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lastLine = end($lines);
    }

    return response()->json([
        'last_line' => $lastLine
    ]);
})->middleware('auth:sanctum');


Route::middleware(['auth:sanctum', CorsMiddleware::class, RoleMiddleware::class, LogUserActivity::class])
    ->group(function () {



        Route::prefix('v1/blogs')->middleware(\App\Http\Middleware\CheckServiceAccess::class . ':manage_blogs')->group(function () {
            Route::get('/mine', [BlogPostController::class, 'mine'])
                ->name('service.blogs.mine');
            Route::post('/', [BlogPostController::class, 'store'])
                ->name('service.blogs.store');
            Route::get('/pending', [SuperAdminBlogController::class, 'pending'])
                ->name('superadmin.blogs.pending');
            Route::get('/{blogPost}', [BlogPostController::class, 'show'])
                ->name('service.blogs.show');
            Route::patch('/{blogPost}', [BlogPostController::class, 'update'])
                ->name('service.blogs.update');
            Route::post('/{blogPost}/submit', [BlogPostController::class, 'submit'])
                ->name('service.blogs.submit');
            Route::post('/{blogPost}/revert', [BlogPostController::class, 'revert'])
                ->name('service.blogs.revert');
            Route::delete('/{blogPost}', [BlogPostController::class, 'destroy'])
                ->name('service.blogs.destroy');
            Route::post('/{blogPost}/approve', [SuperAdminBlogController::class, 'approve'])
                ->name('superadmin.blogs.approve');
            Route::post('/{blogPost}/reject', [SuperAdminBlogController::class, 'reject'])
                ->name('superadmin.blogs.reject');
            Route::post('/{blogPost}/archive', [SuperAdminBlogController::class, 'archive'])
                ->name('superadmin.blogs.archive');
        });

        Route::get('manager/bookables', [ManagerBookableController::class, 'index'])
            ->name('bookings.manager.bookables.index');
        Route::post('manager/bookables', [ManagerBookableController::class, 'store'])
            ->name('bookings.manager.bookables.store');
        Route::get('manager/bookables/{bookable}', [ManagerBookableController::class, 'show'])
            ->name('bookings.manager.bookables.show');
        Route::put('manager/bookables/{bookable}', [ManagerBookableController::class, 'update'])
            ->name('bookings.manager.bookables.update');
        Route::post('manager/bookables/{bookable}/publish', [ManagerBookableController::class, 'publish'])
            ->name('bookings.manager.bookables.publish');
        Route::get('manager/bookables/{bookable}/analytics', [ManagerBookableController::class, 'analytics'])
            ->name('bookings.manager.bookables.analytics');
        Route::delete('manager/bookables/{bookable}', [ManagerBookableController::class, 'destroy'])
            ->name('bookings.manager.bookables.destroy');

        Route::post('manager/tickets/lookup', [TicketVerificationController::class, 'lookup'])
            ->name('bookings.manager.tickets.lookup');
        Route::post('manager/tickets/{ticket}/scan', [TicketVerificationController::class, 'markScanned'])
            ->name('bookings.manager.tickets.scan');

        Route::get('health-dashboard', [HealthStatusController::class, 'index'])
            ->name('superadmin.health-dashboard.index');
        Route::post('health-dashboard/run', [HealthStatusController::class, 'run'])
            ->name('superadmin.health-dashboard.run');

        Route::get('tembea/operator-profile', [TembeaOperatorController::class, 'show'])
            ->name('tembea.operator-profile.show');
        Route::put('tembea/operator-profile', [TembeaOperatorController::class, 'update'])
            ->name('tembea.operator-profile.update');
        Route::get('tembea/settlements', [TembeaOperatorController::class, 'settlements'])
            ->name('tembea.settlements.index');
        Route::post('tembea/settlements/{settlement}/request-payout', [TembeaOperatorController::class, 'requestPayout'])
            ->name('tembea.settlements.request-payout');

        // Super Admin Tembea Management
        Route::prefix('admin/tembea')->middleware(\App\Http\Middleware\CheckServiceAccess::class . ':manage_saccos')->group(function () {
            Route::post('operators/placeholder', [\App\Http\Controllers\SuperAdmin\SuperAdminTembeaController::class, 'createPlaceholderOperator'])
                ->name('superadmin.tembea.operators.placeholder');
            Route::post('operators/{operator}/email', [\App\Http\Controllers\SuperAdmin\SuperAdminTembeaController::class, 'changeOperatorEmail'])
                ->name('superadmin.tembea.operators.email');
            Route::get('operators', [\App\Http\Controllers\SuperAdmin\SuperAdminTembeaController::class, 'index'])
                ->name('superadmin.tembea.operators.index');
            Route::get('analytics', [\App\Http\Controllers\SuperAdmin\SuperAdminTembeaController::class, 'analytics'])
                ->name('superadmin.tembea.analytics');
        });

        Route::prefix('comments')->group(function () {
            Route::post('{comment}/moderate', [CommentController::class, 'moderate'])
                ->name('comments.moderate');
            Route::post('{subjectType}/{subjectId}', [CommentController::class, 'store'])
                ->name('comments.store');
            Route::put('{comment}', [CommentController::class, 'update'])
                ->name('comments.update');
            Route::delete('{comment}', [CommentController::class, 'destroy'])
                ->name('comments.destroy');
        });

        Route::get('admin/refunds', [\App\Http\Controllers\Admin\RefundController::class, 'index']);
        Route::post('admin/refunds', [\App\Http\Controllers\Admin\RefundController::class, 'store']);
        Route::put('admin/refunds/{booking}', [\App\Http\Controllers\Admin\RefundController::class, 'update']);

        Route::get('admin/analytics/zero-result-searches', [\App\Http\Controllers\SuperAdminAnalyticsController::class, 'zeroResultSearches']);
        Route::get('admin/analytics/dead-guides', [\App\Http\Controllers\SuperAdminAnalyticsController::class, 'deadGuides']);

        // Driver Routes
        Route::prefix('driver')->group(function () {
            Route::get('/daily-route', [App\Http\Controllers\DriverController::class, 'getDailyRoute']);
            Route::post('/daily-route', [App\Http\Controllers\DriverController::class, 'setDailyRoute']);
            Route::post('/toggle-shift', [App\Http\Controllers\DriverController::class, 'toggleShift']);
            Route::get('/available-routes', [App\Http\Controllers\DriverController::class, 'getAvailableRoutes']);
        });

        // Tracking Routes (Commuter Facing)
        Route::prefix('tracking')->group(function () {
            Route::get('/vehicles', [VehicleLiveLocationController::class, 'index']);
            Route::post('/incident', [App\Http\Controllers\DriverLocationController::class, 'reportIncident']); // I'll add this method
            Route::get('/fleet/sacco', [App\Http\Controllers\VehicleController::class, 'getSaccoFleet']);
            Route::get('/fleet/owner', [App\Http\Controllers\VehicleController::class, 'getOwnerFleet']);
        });

        // Invitation Routes
        Route::prefix('invitations')->group(function () {
            Route::post('/owner', [OwnerInvitationController::class, 'inviteOwner']);
            Route::post('/driver', [OwnerInvitationController::class, 'inviteDriver']);
        });

    });

Route::middleware([CorsMiddleware::class, LogUserActivity::class])->group(function () {
    Route::post('paystack/verify', [\App\Http\Controllers\PaystackController::class, 'verify'])->name('paystack.verify');
    Route::post('jenga/callback', [JengaController::class, 'callback'])->name('jenga.callback');
    Route::get('payments/{payment}', [JengaController::class, 'show'])->name('payments.show');
    Route::get('payments/reference/{payment:provider_reference}', [JengaController::class, 'show'])->name('payments.show.reference');
    Route::get('comments/{subjectType}/{subjectId}', [CommentController::class, 'index'])
        ->name('comments.index');
    Route::get('bookables/tours', [PublicBookableController::class, 'tourEvents'])
        ->name('bookings.tours.index');
    Route::get('bookables/tours/suggestions', [PublicBookableController::class, 'tourSuggestions'])
        ->name('bookings.tours.suggestions');
    Route::get('bookables/tours/{slug}', [PublicBookableController::class, 'showTourEvent'])
        ->name('bookings.tours.show');
    Route::get('public/tembea/operators/{slug}', [PublicTembeaOperatorController::class, 'show'])
        ->name('public.tembea.operators.show');
    Route::get('bookables/safaris/options', [PublicBookableController::class, 'safariOptions'])
        ->name('bookings.safaris.options');
    Route::get('bookables/safaris/search', [PublicBookableController::class, 'searchSafaris'])
        ->name('bookings.safaris.search');
    Route::get('bookables/safaris/{bookable}', [PublicBookableController::class, 'safariDetail'])
        ->name('bookings.safaris.show');
    Route::post('bookables/{bookable}/checkout', [BookableCheckoutController::class, 'checkout'])
        ->name('bookings.checkout');
    Route::post('bookables/{bookable}/hold', [BookableCheckoutController::class, 'hold'])
        ->name('bookings.hold');
    Route::get('bookings/{booking}/tickets/pdf', TicketDownloadController::class)
        ->name('bookings.tickets.download');

    Route::post('analytics/directions-log', [\App\Http\Controllers\SuperAdminAnalyticsController::class, 'logDirectionSearch']);

    Route::post('search-feedback', [\App\Http\Controllers\SearchFeedbackController::class, 'store']);
    Route::get('admin/analytics/search-feedback', [\App\Http\Controllers\SearchFeedbackController::class, 'analytics'])
        ->middleware([\App\Http\Middleware\RoleMiddleware::class . ':super_admin']);

    Route::get('/direction-threads', [App\Http\Controllers\DirectionThreadController::class, 'index']);
    Route::get('/directions', [App\Http\Controllers\DirectionsController::class, 'index']);
    Route::post('directions/comments', [\App\Http\Controllers\DirectionCommentController::class, 'store']);




    // Parcel Management (requires Sacco Premium tier)
    Route::middleware(\App\Http\Middleware\EnsureParcelFeatureActive::class)->group(function () {
        // Agent Onboarding & Management
        Route::post('sacco-admin/parcel-agents/invite', [\App\Http\Controllers\AgentInvitationController::class, 'invite']);
        Route::get('sacco-admin/{saccoId}/parcel-agents', [\App\Http\Controllers\ParcelAgentController::class, 'index']);
        Route::get('sacco-admin/{saccoId}/parcel-agents/assigned-depots', [\App\Http\Controllers\ParcelAgentController::class, 'assignedDepots']);
        Route::post('sacco-admin/{saccoId}/parcel-agents/{agentId}/approve', [\App\Http\Controllers\ParcelAgentController::class, 'approve']);
        Route::put('sacco-admin/{saccoId}/parcel-agents/{agentId}/permissions', [\App\Http\Controllers\ParcelAgentController::class, 'updatePermissions']);

        // Depot Management
        Route::get('sacco/{saccoId}/depots', [\App\Http\Controllers\ParcelDepotController::class, 'index']);
        Route::post('sacco/{saccoId}/depots', [\App\Http\Controllers\ParcelDepotController::class, 'store']);
        Route::put('sacco/{saccoId}/depots/{depot}', [\App\Http\Controllers\ParcelDepotController::class, 'update']);
        Route::delete('sacco/{saccoId}/depots/{depot}', [\App\Http\Controllers\ParcelDepotController::class, 'destroy']);
        Route::post('sacco/{saccoId}/depots/{depot}/agents/assign', [\App\Http\Controllers\ParcelDepotController::class, 'assignAgent']);
        Route::delete('sacco/{saccoId}/depots/{depot}/agents/remove', [\App\Http\Controllers\ParcelDepotController::class, 'removeAgent']);

        // Parcel Operations
        Route::apiResource('parcels', \App\Http\Controllers\ParcelController::class);
        Route::patch('parcels/{parcel}/status', [\App\Http\Controllers\ParcelController::class, 'updateStatus']);
        Route::post('parcels/{parcel}/deliver', [\App\Http\Controllers\ParcelController::class, 'deliver']);
    });
});

Route::prefix('v2/blogs')->group(function () {
    Route::get('/public', [PublicBlogController::class, 'index'])
        ->name('public.blogs.index');
    Route::get('/public/{slug}', [PublicBlogController::class, 'show'])
        ->name('public.blogs.show');
});

Route::get('search-metrics', function (Request $request, DashboardService $service) {
    $saccoId = $request->query('sacco_id');
    $start = $request->query('start');
    $end = $request->query('end');
    return $service->searchMetrics($saccoId, $start, $end);
})->middleware([CorsMiddleware::class, LogUserActivity::class, 'auth:sanctum']);

Route::post('analytics/tembezi', function (Request $request) {
    $validated = $request->validate([
        'tembezi_id' => 'required|exists:tour_events,id',
        'event_type' => 'required|string',
        'metadata' => 'nullable|array',
    ]);

    \App\Models\TembeziAnalytics::create([
        'tembezi_id' => $validated['tembezi_id'],
        'event_type' => $validated['event_type'],
        'metadata' => $validated['metadata'] ?? null,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ]);

    return response()->json(['message' => 'Event tracked']);
})->middleware([CorsMiddleware::class, LogUserActivity::class]);


Route::post('/login', [AuthController::class, 'login']);
Route::post('/invitations/signup/{token}', [App\Http\Controllers\OwnerInvitationController::class, 'signup']);
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
Route::post('/client-error-logs', [ClientErrorController::class, 'store']);
Route::get('/client-error-logs', [ClientErrorController::class, 'index'])
    ->middleware([\App\Http\Middleware\RoleMiddleware::class . ':super_admin']);

Route::middleware(['auth:sanctum', CorsMiddleware::class])->group(function () {
    Route::get('admin/search-analytics', [\App\Http\Controllers\SearchAnalyticsController::class, 'index'])
        ->middleware(\App\Http\Middleware\CheckServiceAccess::class . ':view_analytics');

    // Super Admin Access Control
    Route::post('admin/access-control/grant', function (Request $request) {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'permission' => 'required|string',
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        if ($user->role !== 'nishukishe_service_person') {
            return response()->json(['message' => 'Access can only be granted to service persons.'], 403);
        }

        $user->permissions()->firstOrCreate(['permission' => $request->permission]);

        return response()->json(['message' => 'Permission granted.']);
    })->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');

    Route::post('admin/access-control/revoke', function (Request $request) {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'permission' => 'required|string',
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        if ($user->role !== 'nishukishe_service_person') {
            return response()->json(['message' => 'This user is not a service person.'], 403);
        }

        $user->permissions()->where('permission', $request->permission)->delete();

        return response()->json(['message' => 'Permission revoked.']);
    })->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');

    Route::prefix('admin/sacco-managers')->middleware(\App\Http\Middleware\CheckServiceAccess::class . ':manage_sacco_managers')->group(function () {
        Route::get('/', [SaccoManagerApprovalController::class, 'index']);
        Route::post('{user}/approve', [SaccoManagerApprovalController::class, 'approve']);
        Route::post('{user}/resend-verification', [SaccoManagerApprovalController::class, 'resendVerification']);
        Route::post('{user}/manual-verify', [SaccoManagerApprovalController::class, 'manualVerify']);
    });

    Route::get('admin/access-control/service-persons', [SaccoManagerApprovalController::class, 'servicePersons'])
        ->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');

    Route::get('superadmin/scalping/stops', [\App\Http\Controllers\SuperAdmin\ScalpingController::class, 'stops'])
        ->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');

    Route::post('superadmin/scalping/search', [\App\Http\Controllers\SuperAdmin\ScalpingController::class, 'search'])
        ->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');

    Route::post('superadmin/scalping/approve', [\App\Http\Controllers\SuperAdmin\ScalpingController::class, 'approve'])
        ->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');


    Route::get('admin/service-map', [\App\Http\Controllers\ServiceMapController::class, 'index']);

    // Email System
    Route::middleware([\App\Http\Middleware\EnsureNishukisheEmail::class])->group(function () {
        Route::get('emails', [\App\Http\Controllers\EmailController::class, 'index']);
        Route::get('emails/{email}/thread', [\App\Http\Controllers\EmailController::class, 'thread']);
        Route::get('emails/{email}', [\App\Http\Controllers\EmailController::class, 'show']);
        Route::post('emails/send', [\App\Http\Controllers\EmailController::class, 'store']);
        Route::apiResource('email-templates', \App\Http\Controllers\EmailTemplateController::class);
    });

});


// Agent Signup (Public)
Route::middleware([\App\Http\Middleware\CorsMiddleware::class])->group(function () {
    Route::get('agent-signup/verify/{token}', [\App\Http\Controllers\AgentInvitationController::class, 'verify']);
    Route::post('agent-signup/{token}', [\App\Http\Controllers\AgentInvitationController::class, 'signup']);
});

Route::get('discover/search', [\App\Http\Controllers\DiscoverController::class, 'search']);
Route::get('sacco/search', [\App\Http\Controllers\SaccoController::class, 'search']);

// Commuter Occurrences
Route::prefix('occurrences')->group(function () {
    Route::get('/', [\App\Http\Controllers\CommuterOccurrenceController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/', [\App\Http\Controllers\CommuterOccurrenceController::class, 'store']);
        Route::post('/{incident}/upvote', [\App\Http\Controllers\CommuterOccurrenceController::class, 'upvote']);
        Route::post('/{incident}/downvote', [\App\Http\Controllers\CommuterOccurrenceController::class, 'downvote']);
    });
});

Route::get('/seo-links', [\App\Http\Controllers\SeoLinksController::class, 'index']);

