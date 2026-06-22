<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),

        // The assistant's persona / guardrails. Override in .env with a single
        // line via ANTHROPIC_SYSTEM_PROMPT, or edit this multi-line default.
        'system_prompt' => env('ANTHROPIC_SYSTEM_PROMPT', <<<'PROMPT'
            You are AiMe BOT, the helpful AI assistant inside the CW Global People
            customer portal. Be concise, friendly, and professional. Answer the user's
            questions directly. If you don't know something specific to their account,
            say so plainly rather than guessing. If asked your name, you are AiMe BOT.
            PROMPT),

        // Models a user may pick in the chat UI (id => label).
        'models' => [
            'claude-opus-4-8' => 'Claude Opus 4.8 — most capable',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6 — balanced',
            'claude-haiku-4-5' => 'Claude Haiku 4.5 — fastest',
        ],
    ],

];
