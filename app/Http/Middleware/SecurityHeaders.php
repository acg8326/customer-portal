<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds standard security headers to every web response — clickjacking,
 * MIME-sniffing, referrer, and a Content-Security-Policy as defense-in-depth
 * on top of DOMPurify sanitization of model output. Everything is tunable via
 * config/security.php (.env).
 *
 * The CSP is only sent when serving built assets (production). While the Vite
 * dev server is running (public/hot exists) it is skipped entirely: Chrome
 * rejects IPv6 literal sources — and Vite often binds [::1] — so the dev
 * origin can't reliably be whitelisted, and dev tooling (Vite HMR, Laravel
 * Boost's browser logger) injects nonce-less inline scripts a strict policy
 * would block. A CSP on localhost protects nothing; in production it always
 * ships, with a per-request nonce so the layout's inline theme-init script
 * runs without 'unsafe-inline'.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $sendCsp = config('security.headers.enabled', true)
            && config('security.headers.csp', true)
            && $this->viteDevServer() === null;

        // The nonce must exist before the view renders so @vite tags and the
        // layout's inline script can carry it.
        if ($sendCsp) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($sendCsp) {
            $response->headers->set('Content-Security-Policy', $this->csp());
        }

        return $response;
    }

    /**
     * The configured policy with the per-request script nonce added, so the
     * blade layout's inline theme script runs under a strict script-src.
     */
    private function csp(): string
    {
        return str_replace(
            "script-src 'self'",
            "script-src 'self' 'nonce-".Vite::cspNonce()."'",
            (string) config('security.headers.csp_policy'),
        );
    }

    /**
     * The Vite dev-server origin from public/hot, or null when not running
     * (i.e. built assets / production).
     */
    private function viteDevServer(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $dev = trim((string) file_get_contents($hotFile));

        return $dev !== '' && str_starts_with($dev, 'http') ? $dev : null;
    }
}
