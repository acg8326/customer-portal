<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Per-user rolling token budget across THREE independent windows (like
 * Claude's own usage limits): a short session window, a weekly window, and a
 * longer period window. A user may spend up to each window's limit before
 * that window blocks further use; any window resets automatically once it
 * elapses, on its own schedule.
 */
class TokenBudget
{
    /** @var array<string, TokenBudgetTier>|null */
    private static ?array $tiers = null;

    /**
     * The three rolling windows, keyed by name. Iterating this array — rather
     * than hand-writing refresh/exceeded/record/snapshot per tier — is what
     * keeps all three windows' logic in one place.
     *
     * @return array<string, TokenBudgetTier>
     */
    private function tiers(): array
    {
        return self::$tiers ??= [
            'period' => new TokenBudgetTier(
                key: 'period',
                usedColumn: 'token_budget_used',
                startedAtColumn: 'token_budget_started_at',
                perUserLimitColumn: 'token_limit',
                limitSettingKey: 'usage.token_limit',
                limitConfigKey: 'usage.token_limit',
                limitConfigDefault: 1_000_000,
                durationSettingKey: 'usage.period_days',
                durationConfigKey: 'usage.period_days',
                durationConfigDefault: 30,
                durationUnit: 'days',
            ),
            'session' => new TokenBudgetTier(
                key: 'session',
                usedColumn: 'session_budget_used',
                startedAtColumn: 'session_budget_started_at',
                perUserLimitColumn: 'session_token_limit',
                limitSettingKey: 'usage.session_token_limit',
                limitConfigKey: 'usage.session_token_limit',
                limitConfigDefault: 0,
                durationSettingKey: 'usage.session_hours',
                durationConfigKey: 'usage.session_hours',
                durationConfigDefault: 5,
                durationUnit: 'hours',
            ),
            'weekly' => new TokenBudgetTier(
                key: 'weekly',
                usedColumn: 'weekly_budget_used',
                startedAtColumn: 'weekly_budget_started_at',
                perUserLimitColumn: 'weekly_token_limit',
                limitSettingKey: 'usage.weekly_token_limit',
                limitConfigKey: 'usage.weekly_token_limit',
                limitConfigDefault: 0,
                durationSettingKey: 'usage.weekly_days',
                durationConfigKey: 'usage.weekly_days',
                durationConfigDefault: 7,
                durationUnit: 'days',
            ),
        ];
    }

    /**
     * Priority order for reporting WHICH tier is blocking a request — the
     * shortest window first, since that's the more immediate, more
     * actionable constraint to surface ("try again in 2 hours" beats "try
     * again in 3 weeks" when both are technically true).
     *
     * @return string[]
     */
    private function tierPriority(): array
    {
        return ['session', 'weekly', 'period'];
    }

    /**
     * Ensure every window is current, rolling any elapsed one forward.
     * Persists once (not once per tier) when any reset happened.
     */
    public function refresh(User $user): User
    {
        $dirty = false;

        foreach ($this->tiers() as $tier) {
            $start = $user->{$tier->startedAtColumn};

            if ($start === null || Carbon::now()->greaterThanOrEqualTo($this->tierEnd($start, $tier))) {
                $user->{$tier->startedAtColumn} = Carbon::now();
                $user->{$tier->usedColumn} = 0;
                $dirty = true;
            }
        }

        if ($dirty) {
            $user->save();
        }

        return $user;
    }

    /**
     * Has the user hit ANY enabled tier's cap right now?
     */
    public function exceeded(User $user): bool
    {
        return $this->firstExceededTier($user) !== null;
    }

    /**
     * Which tier (if any) is currently blocking this user — the tightest one
     * per tierPriority(), i.e. the one whose reset is soonest. Null when
     * tracking is off or every enabled tier still has room.
     */
    public function firstExceededTier(User $user): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $this->refresh($user);

        foreach ($this->tierPriority() as $key) {
            $tier = $this->tiers()[$key];
            $limit = $this->tierLimit($user, $tier);

            if ($limit > 0 && $user->{$tier->usedColumn} >= $limit) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Add spent tokens to ALL THREE current windows (one API call counts
     * against the session, weekly, and period allowances at once).
     */
    public function record(User $user, int $tokens): void
    {
        if (! $this->enabled() || $tokens <= 0) {
            return;
        }

        $this->refresh($user);

        foreach ($this->tiers() as $tier) {
            $user->{$tier->usedColumn} += $tokens;
        }

        $user->save();
    }

    /**
     * A display-ready snapshot of the user's budget (for the dashboard).
     *
     * Top-level keys (`enabled`, `used`, `limit`, `remaining`, `percent`,
     * `resets_at`, `period_days`) are the PERIOD tier, kept flat for
     * backward compatibility. `period`, `session`, and `weekly` carry the
     * same five fields (plus each tier's own duration field) nested, for
     * callers that want a specific tier or want to render all three
     * uniformly.
     *
     * @return array{
     *     enabled: bool, used: int, limit: int, remaining: int, percent: float,
     *     resets_at: string|null, period_days: int,
     *     period: array{enabled: bool, used: int, limit: int, remaining: int, percent: float, resets_at: string|null, period_days: int},
     *     session: array{enabled: bool, used: int, limit: int, remaining: int, percent: float, resets_at: string|null, session_hours: int},
     *     weekly: array{enabled: bool, used: int, limit: int, remaining: int, percent: float, resets_at: string|null, weekly_days: int},
     * }
     */
    public function snapshot(User $user): array
    {
        $this->refresh($user);

        $period = $this->tierSnapshot($user, $this->tiers()['period']);
        $session = $this->tierSnapshot($user, $this->tiers()['session']);
        $weekly = $this->tierSnapshot($user, $this->tiers()['weekly']);

        $base = fn (array $t): array => [
            'enabled' => $t['enabled'],
            'used' => $t['used'],
            'limit' => $t['limit'],
            'remaining' => $t['remaining'],
            'percent' => $t['percent'],
            'resets_at' => $t['resets_at'],
        ];

        return [
            ...$base($period),
            'period_days' => $period['duration'],
            'period' => [...$base($period), 'period_days' => $period['duration']],
            'session' => [...$base($session), 'session_hours' => $session['duration']],
            'weekly' => [...$base($weekly), 'weekly_days' => $weekly['duration']],
        ];
    }

    /**
     * @return array{enabled: bool, used: int, limit: int, remaining: int, percent: float, resets_at: string|null, duration: int}
     */
    private function tierSnapshot(User $user, TokenBudgetTier $tier): array
    {
        $limit = $this->tierLimit($user, $tier);
        $used = (int) $user->{$tier->usedColumn};
        $capActive = $this->enabled() && $limit > 0;
        $remaining = $capActive ? max(0, $limit - $used) : 0;
        $percent = $capActive ? min(100, round($used / $limit * 100, 1)) : 0.0;
        $startedAt = $user->{$tier->startedAtColumn};

        return [
            'enabled' => $capActive,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'percent' => $percent,
            'resets_at' => $startedAt ? $this->tierEnd($startedAt, $tier)->toIso8601String() : null,
            'duration' => $this->tierDuration($tier),
        ];
    }

    private function tierEnd(CarbonInterface $start, TokenBudgetTier $tier): CarbonInterface
    {
        $value = $this->tierDuration($tier);

        return $tier->durationUnit === 'hours'
            ? $start->copy()->addHours($value)
            : $start->copy()->addDays($value);
    }

    private function enabled(): bool
    {
        return (bool) config('usage.enabled', true);
    }

    private function tierLimit(User $user, TokenBudgetTier $tier): int
    {
        $override = $user->{$tier->perUserLimitColumn};

        if ($override !== null) {
            return (int) $override;
        }

        return app(AppSettings::class)->int(
            $tier->limitSettingKey,
            (int) config($tier->limitConfigKey, $tier->limitConfigDefault),
        );
    }

    private function tierDuration(TokenBudgetTier $tier): int
    {
        return max(1, app(AppSettings::class)->int(
            $tier->durationSettingKey,
            (int) config($tier->durationConfigKey, $tier->durationConfigDefault),
        ));
    }
}
