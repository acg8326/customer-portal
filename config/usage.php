<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-user token budget
    |--------------------------------------------------------------------------
    |
    | Each user gets THREE independent rolling token allowances, mirroring how
    | Claude's own usage limits work: a short session window, a weekly
    | window, and a longer period window. Any window elapsing resets its own
    | counter on its own schedule; hitting any ENABLED window's cap (a cap is
    | "enabled" only when > 0) blocks further use until the tightest
    | exhausted window resets.
    |
    | token_limit          — tokens allowed per PERIOD window. 0 (or
    |                        negative) = UNLIMITED for that window: usage is
    |                        still tracked and shown, but never blocks.
    | period_days          — length of the period window in days (default
    |                        30 = ~monthly).
    | session_token_limit  — tokens allowed per SESSION window. Same
    |                        0 = unlimited semantics as token_limit.
    | session_hours        — length of the session window in hours (default
    |                        5, like Claude's own session limit).
    | weekly_token_limit   — tokens allowed per WEEKLY window. Same
    |                        0 = unlimited semantics as token_limit.
    | weekly_days          — length of the weekly window in days (default 7).
    | enabled              — master switch for tracking/display across ALL
    |                        THREE windows; disable to turn the whole
    |                        feature off.
    |
    | Prompt-cache weighting
    | ----------------------
    | Cached prompt tokens don't cost what fresh ones do: Anthropic bills a
    | cache READ at ~0.1x the input price and a cache WRITE at ~1.25x. Budgets
    | charge each class at these weights so a coding agent replaying a large
    | cached prefix every turn isn't billed as if the prefix were new. Keep
    | these in step with services.llm_pricing.cache_*_multiplier (which prices
    | the same tokens in dollars on Analytics).
    |
    | cache_read_weight    — 0 makes cache reads free; 1 charges them like
    |                        fresh input.
    | cache_write_weight   — writes are a real premium; 1.25 mirrors billing.
    |
    */

    'token_limit' => (int) env('USAGE_TOKEN_LIMIT', 0),

    'period_days' => (int) env('USAGE_PERIOD_DAYS', 30),

    'session_token_limit' => (int) env('USAGE_SESSION_TOKEN_LIMIT', 0),

    'session_hours' => (int) env('USAGE_SESSION_HOURS', 5),

    'weekly_token_limit' => (int) env('USAGE_WEEKLY_TOKEN_LIMIT', 0),

    'weekly_days' => (int) env('USAGE_WEEKLY_DAYS', 7),

    'enabled' => (bool) env('USAGE_LIMIT_ENABLED', true),

    'cache_read_weight' => (float) env('USAGE_CACHE_READ_WEIGHT', 0.1),

    'cache_write_weight' => (float) env('USAGE_CACHE_WRITE_WEIGHT', 1.25),

];
