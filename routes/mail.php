<?php

use Illuminate\Support\Facades\Mail;
use App\Mail\TestEmail;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\MailController;
use Illuminate\Support\Facades\Route;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\RoleMiddleware;


Route::get('/send-email', function () {
    Mail::to('kniteowl80@gmail.com')->send(
        new TestEmail('Hello from Laravel', 'This is a raw plain-text message.')
    );
    return response()->json([
        'status' => 'success',
        'message' => 'Email sent successfully',
    ]);
})->name('send.email');
//receieve email


Route::get('/getAllEmails', [MailController::class, 'getAllMails'])
    ->name('get.all.emails')
    ->middleware([CorsMiddleware::class,LogUserActivity::class,RoleMiddleware::class]);

