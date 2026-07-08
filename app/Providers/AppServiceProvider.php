<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
    }

    /**
     * Named request rate limiters. Keyed per-user (IP fallback) so one user's
     * burst never affects another, with a friendly "slow down" response and a
     * Retry-After header. Limits live in config/ratelimits.php (.env-tunable).
     */
    protected function configureRateLimiters(): void
    {
        // Map limiter name → config key. The per-minute value is read live
        // inside the closure so an .env change takes effect on config:clear
        // (and so tests can adjust a limit without re-registering).
        $rules = [
            'chat' => 'ratelimits.chat',
            'search' => 'ratelimits.search',
            'integrations' => 'ratelimits.integrations',
            'integration-test' => 'ratelimits.integration_test',
        ];

        foreach ($rules as $name => $configKey) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute((int) config($configKey, 30))
                ->by($request->user()?->id ? 'u'.$request->user()->id : 'ip'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => "You're doing that a bit fast — please wait a moment and try again.",
                ], 429, $headers)));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
