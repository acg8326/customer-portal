<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live providers
    |--------------------------------------------------------------------------
    |
    | Which integration providers are actually wired up (their Connect button
    | works). Everything else on the Integrations page is a "coming soon"
    | placeholder. Add a provider key here once its backend exists.
    |
    */

    'live' => array_filter(explode(',', (string) env(
        'INTEGRATIONS_LIVE',
        'n8n',
    ))),

    /*
    |--------------------------------------------------------------------------
    | n8n
    |--------------------------------------------------------------------------
    |
    | Outbound webhook connector. Each user stores their own n8n Webhook node
    | URL (+ optional secret); we POST events to it. Nothing here is a secret —
    | per-user URLs/secrets live (encrypted) in the user_integrations table.
    |
    */

    'n8n' => [
        // Seconds to wait when POSTing an event to a user's n8n webhook.
        'timeout' => (int) env('INTEGRATION_N8N_TIMEOUT', 8),

        // Header used to carry the user's shared secret (if they set one).
        'secret_header' => env('INTEGRATION_N8N_SECRET_HEADER', 'X-AiMe-Secret'),
    ],

];
