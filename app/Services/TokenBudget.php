<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Per-user rolling token budget (like Claude's usage limits).
 *
 * A user may spend up to `usage.token_limit` tokens per `usage.period_days`
 * window. The window resets automatically once it elapses.
 */
class TokenBudget
{
    /**
     * Ensure the user's window is current, rolling it forward if it elapsed.
     * Returns the user for chaining. Persists only when a reset happened.
     */
    public function refresh(User $user): User
    {
        $start = $user->token_budget_started_at;

        if ($start === null) {
            $user->token_budget_started_at = Carbon::now();
            $user->token_budget_used = 0;
            $user->save();

            return $user;
        }

        if (Carbon::now()->greaterThanOrEqualTo($this->periodEnd($start))) {
            // Advance to a fresh window anchored at now.
            $user->token_budget_started_at = Carbon::now();
            $user->token_budget_used = 0;
            $user->save();
        }

        return $user;
    }

    /**
     * Has the user spent their whole allowance for the current window?
     */
    public function exceeded(User $user): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $this->refresh($user);

        return $user->token_budget_used >= $this->limit();
    }

    /**
     * Add spent tokens to the current window.
     */
    public function record(User $user, int $tokens): void
    {
        if (! $this->enabled() || $tokens <= 0) {
            return;
        }

        $this->refresh($user);

        $user->token_budget_used += $tokens;
        $user->save();
    }

    /**
     * A display-ready snapshot of the user's budget (for the dashboard).
     *
     * @return array{
     *     enabled: bool,
     *     used: int,
     *     limit: int,
     *     remaining: int,
     *     percent: float,
     *     resets_at: string|null,
     *     period_days: int,
     * }
     */
    public function snapshot(User $user): array
    {
        $this->refresh($user);

        $limit = $this->limit();
        $used = (int) $user->token_budget_used;
        $remaining = max(0, $limit - $used);
        $percent = $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0.0;

        return [
            'enabled' => $this->enabled(),
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'percent' => $percent,
            'resets_at' => $user->token_budget_started_at
                ? $this->periodEnd($user->token_budget_started_at)->toIso8601String()
                : null,
            'period_days' => $this->periodDays(),
        ];
    }

    private function periodEnd(CarbonInterface $start): CarbonInterface
    {
        return $start->copy()->addDays($this->periodDays());
    }

    private function enabled(): bool
    {
        return (bool) config('usage.enabled', true);
    }

    private function limit(): int
    {
        return (int) config('usage.token_limit', 1_000_000);
    }

    private function periodDays(): int
    {
        return max(1, (int) config('usage.period_days', 30));
    }
}
