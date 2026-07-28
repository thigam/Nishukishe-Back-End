<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'auth/login',
        'auth/register',
        'auth/logout',
        'auth/success',
        'auth/*',
        '/auth/login',
        '/auth/register',
        '/auth/logout',
        '/auth/success',
        '/auth/*',
        'api/auth/login',
        'api/auth/register',
        'receive-email',
        'receive-email/*',
    ];
}

