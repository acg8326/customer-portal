<?php

use App\Http\Middleware\SecurityHeaders;
use App\Services\UploadScanner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Vite;

/**
 * Point public_path() at a scratch dir so tests control whether a Vite "hot"
 * file exists — without touching the real public/hot of a running dev server.
 */
function fakePublicPath(?string $hotOrigin = null): string
{
    $dir = sys_get_temp_dir().'/csp-test-'.uniqid();
    File::makeDirectory($dir, recursive: true);

    if ($hotOrigin !== null) {
        File::put($dir.'/hot', $hotOrigin);
    }

    app()->usePublicPath($dir);

    return $dir;
}

/**
 * Run the middleware around a bare response — no view render, so the outcome
 * depends only on config + the (faked) public path, not on whether a real
 * `npm run dev` happens to be running.
 */
function securityHeadersFor(): Symfony\Component\HttpFoundation\Response
{
    return (new SecurityHeaders)->handle(
        Request::create('/x'),
        fn () => new Response('ok'),
    );
}

// --- security headers ---------------------------------------------------------

test('security headers are set on web responses', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('the production CSP carries a script nonce and stays strict', function () {
    fakePublicPath(); // no hot file → production branch

    $csp = (string) securityHeadersFor()->headers->get('Content-Security-Policy');

    // script-src is exactly self + the per-request nonce — no 'unsafe-inline',
    // no dev origins — and the nonce is exposed for the blade layout.
    expect($csp)->toContain("script-src 'self' 'nonce-".Vite::cspNonce()."';")
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->not->toContain('5173')
        ->and((string) Vite::cspNonce())->not->toBe('');
});

test('no CSP is sent while the Vite dev server is running', function () {
    // Chrome rejects IPv6 sources like [::1], which Vite often binds — the
    // middleware skips the CSP in dev instead of shipping a broken one.
    fakePublicPath('http://[::1]:5173');

    $headers = securityHeadersFor()->headers;

    expect($headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the CSP header can be disabled independently', function () {
    config(['security.headers.csp' => false]);
    fakePublicPath(); // production branch — would otherwise send it

    $headers = securityHeadersFor()->headers;

    expect($headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('all security headers can be disabled', function () {
    config(['security.headers.enabled' => false]);
    fakePublicPath();

    expect(securityHeadersFor()->headers->has('X-Content-Type-Options'))->toBeFalse();
});

// --- upload scanning -----------------------------------------------------------

test('upload scanning is a no-op when disabled', function () {
    config(['security.uploads.scan' => false]);

    $file = UploadedFile::fake()->create('ok.png', 10, 'image/png');

    app(UploadScanner::class)->assertClean($file);

    expect(true)->toBeTrue(); // no exception thrown
});

test('an infected upload is rejected', function () {
    config(['security.uploads.scan' => true]);
    Process::fake(['*' => Process::result(exitCode: 1, output: 'Eicar-Test-Signature FOUND')]);

    $file = UploadedFile::fake()->create('bad.png', 10, 'image/png');

    expect(fn () => app(UploadScanner::class)->assertClean($file))
        ->toThrow(RuntimeException::class, 'rejected by the virus scanner');
});

test('a broken scanner fails closed when scanning is enabled', function () {
    config(['security.uploads.scan' => true]);
    Process::fake(['*' => Process::result(exitCode: 2, errorOutput: 'clamscan: not found')]);

    $file = UploadedFile::fake()->create('any.png', 10, 'image/png');

    expect(fn () => app(UploadScanner::class)->assertClean($file))
        ->toThrow(RuntimeException::class, 'scanning is unavailable');
});

test('a clean upload passes', function () {
    config(['security.uploads.scan' => true]);
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $file = UploadedFile::fake()->create('fine.png', 10, 'image/png');

    app(UploadScanner::class)->assertClean($file);

    expect(true)->toBeTrue();
});
