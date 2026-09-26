<?php

use App\Http\Middleware\AuditAdminActions;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RejectOversizedRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackVisitorSession;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lets the web SPA authenticate via first-party, httpOnly session cookies
        // (CSRF + session validated automatically for requests from a domain
        // listed in SANCTUM_STATEFUL_DOMAINS) while leaving Bearer-token
        // authentication for every other client (mobile apps, Postman, etc.)
        // completely untouched — both mechanisms work side by side.
        $middleware->statefulApi();

        $middleware->alias([
            'audit.admin' => AuditAdminActions::class,
            'active.user' => EnsureActiveUser::class,
        ]);

        // Run authorization gates before implicit route-model binding so an
        // unauthorized caller cannot probe whether an admin resource exists.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            Authorize::class,
            SubstituteBindings::class,
        ]);

        $middleware->encryptCookies(except: [
            'visitor_id',
            'visitor_session_id',
        ]);
        $middleware->use([
            HandleCors::class,
            RejectOversizedRequests::class,
            SecurityHeaders::class,
            TrackVisitorSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
