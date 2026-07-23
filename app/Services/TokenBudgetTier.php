<?php

namespace App\Services;

/**
 * Configuration for one of TokenBudget's three rolling windows (period,
 * session, weekly) — the column names, override column, and where its limit
 * and duration are resolved from. Letting TokenBudget iterate a list of these
 * instead of hand-writing the same refresh/exceeded/record/snapshot logic
 * three times.
 */
final readonly class TokenBudgetTier
{
    public function __construct(
        public string $key,
        public string $usedColumn,
        public string $startedAtColumn,
        public string $perUserLimitColumn,
        public string $limitSettingKey,
        public string $limitConfigKey,
        public int $limitConfigDefault,
        public string $durationSettingKey,
        public string $durationConfigKey,
        public int $durationConfigDefault,
        public string $durationUnit,
    ) {}
}
