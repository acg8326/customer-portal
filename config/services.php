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

        // Models a user may pick in the chat UI (id => label). Add/remove freely;
        // ids are validated server-side against this list.
        'models' => [
            'claude-opus-4-8' => 'Claude Opus 4.8 — most capable',
            'claude-opus-4-7' => 'Claude Opus 4.7',
            'claude-opus-4-1' => 'Claude Opus 4.1',
            'claude-sonnet-5' => 'Claude Sonnet 5 — balanced',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
            'claude-sonnet-4-5' => 'Claude Sonnet 4.5',
            'claude-haiku-4-5' => 'Claude Haiku 4.5 — fastest',
            'claude-fable-5' => 'Claude Fable 5 — creative writing',
        ],

        // Chat file uploads (images + PDFs). Claude reads these natively; each
        // attachment is re-sent with every turn so follow-up questions keep the
        // file in view. All tunables here are .env-overridable.
        'uploads' => [
            'enabled' => (bool) env('ANTHROPIC_UPLOADS_ENABLED', true),
            'max_files' => (int) env('ANTHROPIC_UPLOADS_MAX_FILES', 5),
            'max_size_kb' => (int) env('ANTHROPIC_UPLOADS_MAX_SIZE_KB', 10240),
            // Comma-separated file extensions accepted by the picker + validator.
            'mimes' => env('ANTHROPIC_UPLOADS_MIMES', 'jpg,jpeg,png,gif,webp,pdf'),
        ],
    ],

];
