<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-user token budget
    |--------------------------------------------------------------------------
    |
    | Each user gets a rolling token allowance (input + output tokens counted
    | from the Claude API `usage`). When the window elapses the counter resets,
    | the same way Claude's own usage limits reset on a schedule.
    |
    | token_limit  — tokens allowed per window. 0 (or negative) = UNLIMITED:
    |                usage is still tracked and shown, but never blocks.
    | period_days  — length of the window in days (default 30 = ~monthly).
    | enabled      — master switch for tracking/display; disable to turn the
    |                whole feature off.
    |
    */

    'token_limit' => (int) env('USAGE_TOKEN_LIMIT', 0),

    'period_days' => (int) env('USAGE_PERIOD_DAYS', 30),

    'enabled' => (bool) env('USAGE_LIMIT_ENABLED', true),

];
