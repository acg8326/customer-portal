<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every page except Settings → Security (and the auth/password
 * routes needed to get there) until a member changes the password an admin
 * generated for them. Global so new routes are covered automatically —
 * only the exempt list below needs to grow.
 */
class EnsurePasswordHasBeenChanged
{
    private const EXEMPT_ROUTES = [
        'login', 'login.store', 'logout',
        'two-factor.login', 'two-factor.login.store',
        'password.request', 'password.email', 'password.reset', 'password.update',
        'password.confirm', 'password.confirm.store', 'password.confirmation',
        'verification.notice', 'verification.verify', 'verification.send',
        'security.edit', 'user-password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs(...self::EXEMPT_ROUTES)) {
            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
