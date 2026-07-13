<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Org-wide runtime settings, editable in the UI (super admin) and stored in
 * the app_settings table. Each key overrides a config/.env default — the
 * .env value remains the fallback when no row exists, so deployments without
 * UI changes behave exactly as configured.
 *
 * The whole table is one cached map (invalidated on write): setting lookups
 * happen on every chat turn via TokenBudget and must not add queries.
 */
class AppSettings
{
    private const CACHE_KEY = 'app-settings';

    /**
     * The stored override for a key, or null when unset.
     */
    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * An integer setting with its config/.env fallback.
     */
    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    /**
     * Store an override (null or '' removes it → back to the .env default).
     */
    public function set(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            DB::table('app_settings')->where('key', $key)->delete();
        } else {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        /** @var array<string, string> */
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => DB::table('app_settings')->pluck('value', 'key')->all(),
        );
    }
}
