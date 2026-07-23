<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Captures Anthropic's own org-wide rate-limit response headers
 * (anthropic-ratelimit-{dimension}-{limit|remaining|reset}) into a short-TTL
 * cache snapshot, so the super admin can see how close the shared API key is
 * to Anthropic's limits.
 *
 * Gateway (/llm/v1) traffic only — the in-app chat goes through the Anthropic
 * PHP SDK, which returns typed response objects and doesn't expose raw HTTP
 * response headers, so there is no capture point on that path.
 *
 * A cache (not a table) is deliberate: these headers describe shared,
 * point-in-time account state, not per-user history — there's nothing to
 * query by date or user. The TTL also makes the value naturally go stale once
 * gateway traffic stops, which a DB row wouldn't do without extra logic.
 */
class AnthropicRateLimits
{
    private const HEADER_PATTERN = '/^anthropic-ratelimit-(.+)-(limit|remaining|reset)$/i';

    private const CACHE_KEY = 'gateway:rate_limits';

    public static function capture(Response $response): void
    {
        if (! config('services.anthropic.rate_limit_capture_enabled', true)) {
            return;
        }

        $dimensions = [];

        foreach ($response->headers() as $name => $values) {
            if (preg_match(self::HEADER_PATTERN, (string) $name, $m)) {
                $dimensions[$m[1]][$m[2]] = $values[0] ?? null;
            }
        }

        if ($dimensions === []) {
            return;
        }

        Cache::put(self::CACHE_KEY, [
            'dimensions' => $dimensions,
            'captured_at' => now()->toIso8601String(),
        ], now()->addSeconds((int) config('services.anthropic.rate_limit_cache_ttl', 300)));
    }

    /**
     * @return array{dimensions: array<string, array{limit?: string, remaining?: string, reset?: string}>, captured_at: string}|null
     */
    public static function current(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }
}
