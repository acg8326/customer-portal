<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request rate limits (per authenticated user, per minute)
    |--------------------------------------------------------------------------
    |
    | These guard against runaway client loops and endpoint abuse — NOT normal
    | use. Defaults are set well above what a real person does, so legitimate
    | customers should never hit them. Keyed per-user (falling back to IP), so
    | one user's burst never affects anyone else. Raise any value via .env if a
    | power user ever bumps into it — no deploy needed.
    |
    | A human in a chat sends at most a handful of messages a minute; 30 gives
    | ~6x headroom. Tune down only if you're seeing abuse.
    |
    */

    // Sending chat messages (the expensive, Claude-calling path).
    'chat' => (int) env('RATE_LIMIT_CHAT', 30),

    // Searching your own chats (debounced client-side already).
    'search' => (int) env('RATE_LIMIT_SEARCH', 60),

    // Connecting / disconnecting integrations (rare, click-driven actions).
    'integrations' => (int) env('RATE_LIMIT_INTEGRATIONS', 20),

    // Firing a test event to an n8n webhook (makes an outbound request —
    // kept tighter since it's the most abusable endpoint).
    'integration_test' => (int) env('RATE_LIMIT_INTEGRATION_TEST', 10),

];
