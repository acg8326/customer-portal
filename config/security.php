<?php

return [

    // Response security headers (added by App\Http\Middleware\SecurityHeaders).
    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS', true),

        // Content-Security-Policy. Defense-in-depth on top of DOMPurify — even
        // if sanitization ever missed something, injected scripts can't load or
        // execute. 'unsafe-inline' styles are required by Vue/shadcn inline
        // style attributes; img allows data:/blob: for favicons and client-side
        // file previews/downloads. Only sent when serving BUILT assets, with a
        // per-request script nonce added for the layout's inline theme script.
        // While the Vite dev server runs (public/hot exists) the header is
        // skipped: browsers reject IPv6 CSP sources like [::1] (which Vite
        // often binds), and dev tooling injects nonce-less inline scripts — a
        // CSP on localhost would only break the UI, not protect anything.
        'csp' => (bool) env('SECURITY_CSP', true),
        'csp_policy' => env('SECURITY_CSP_POLICY',
            "default-src 'self'; "
            ."script-src 'self'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."font-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-ancestors 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'; "
            ."object-src 'none'"
        ),
    ],

    // Chat file-upload virus scanning (ClamAV). OFF by default — Laravel's
    // `mimes:` validation already content-sniffs the real file type, so this is
    // an extra layer for hosts with ClamAV installed. When ENABLED, scanning is
    // FAIL-CLOSED: a missing/erroring scanner rejects the upload (loudly)
    // rather than silently skipping the scan.
    'uploads' => [
        'scan' => (bool) env('SECURITY_UPLOAD_SCAN', false),
        // clamscan is the standalone binary; clamdscan (daemon) is much faster
        // if clamd is running — point this at whichever the server has.
        'scanner' => env('SECURITY_UPLOAD_SCANNER', 'clamscan'),
        'scan_timeout' => (int) env('SECURITY_UPLOAD_SCAN_TIMEOUT', 30),
    ],

];
