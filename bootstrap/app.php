<?php

use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\GatewayAuth;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Token-authenticated LLM gateway at /llm/v1 — no web session or CSRF;
        // developers point Claude Code here (see routes/gateway.php).
        then: function (): void {
            Route::middleware(GatewayAuth::class)
                ->prefix('llm/v1')
                ->group(base_path('routes/gateway.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
            EnsurePasswordHasBeenChanged::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'super_admin' => EnsureUserIsSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                // LLM gateway is an API surface (Claude Code) — never HTML.
                || $request->is('llm/*')
                || $request->is('chat/message')
                || $request->is('chat/stream')
                || $request->is('chat/search')
                || $request->is('chat/conversations/*')
                || $request->is('chat/messages/*')
                || $request->is('chat/export/*')
                // NetSuite connect/test are called via fetch and expect JSON
                // (validation errors included), not a web redirect.
                || $request->is('integrations/netsuite/connect')
                || $request->is('integrations/netsuite/test'),
        );
    })->create();
