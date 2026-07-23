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
    */

    'token_limit' => (int) env('USAGE_TOKEN_LIMIT', 0),

    'period_days' => (int) env('USAGE_PERIOD_DAYS', 30),

    'session_token_limit' => (int) env('USAGE_SESSION_TOKEN_LIMIT', 0),

    'session_hours' => (int) env('USAGE_SESSION_HOURS', 5),

    'weekly_token_limit' => (int) env('USAGE_WEEKLY_TOKEN_LIMIT', 0),

    'weekly_days' => (int) env('USAGE_WEEKLY_DAYS', 7),

    'enabled' => (bool) env('USAGE_LIMIT_ENABLED', true),

];
