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

    // Composio — a hosted tool gateway. One API key lets each user connect apps
    // (Slack, GitHub, …) via Composio-managed OAuth, reached over MCP with no
    // per-app client id/secret. Each toolkit needs an auth-config id (created
    // once in the Composio dashboard) and an MCP server id (the <id> from the
    // MCP URL Composio shows: /v3/mcp/<id>?user_id=...).
    'composio' => [
        'api_key' => env('COMPOSIO_API_KEY'),
        'base_url' => env('COMPOSIO_BASE_URL', 'https://backend.composio.dev'),

        // Max tool schemas per toolkit sent to Claude in a turn (keeps the prompt
        // from ballooning), and max tool-call rounds before we stop the loop.
        'max_tools' => (int) env('COMPOSIO_MAX_TOOLS', 100),
        'max_tool_rounds' => (int) env('COMPOSIO_MAX_TOOL_ROUNDS', 8),

        // Toolkit/tool version used when listing and executing tools. MUST be set
        // (e.g. 'latest') — the API's implicit default resolves some toolkits
        // (NetSuite) to an EMPTY version, so tools vanish / execute returns 404.
        'tool_version' => env('COMPOSIO_TOOL_VERSION', 'latest'),

        'toolkits' => [
            'slack' => [
                'name' => 'Slack',
                'auth_config_id' => env('COMPOSIO_SLACK_AUTH_CONFIG'),
            ],
            'github' => [
                'name' => 'GitHub',
                'auth_config_id' => env('COMPOSIO_GITHUB_AUTH_CONFIG'),
            ],
            'hubspot' => [
                'name' => 'HubSpot',
                'auth_config_id' => env('COMPOSIO_HUBSPOT_AUTH_CONFIG'),
            ],
            'airtable' => [
                'name' => 'Airtable',
                'auth_config_id' => env('COMPOSIO_AIRTABLE_AUTH_CONFIG'),
            ],
        ],
    ],

    // NetSuite — a NATIVE integration using Token-Based Authentication (TBA,
    // OAuth 1.0a) against SuiteTalk REST + SuiteQL, the way NetSuite itself
    // recommends for server-to-server access. This bypasses Composio entirely
    // (Composio's NetSuite toolkit only supports OAuth 2.0, whose tokens can't
    // reliably read records). Each user pastes the five values from their
    // NetSuite account (Account ID + the Integration record's Consumer
    // Key/Secret + an Access Token's Token ID/Secret); we sign each request.
    'netsuite' => [
        'enabled' => (bool) env('NETSUITE_ENABLED', true),
        // Request timeout (seconds) and the row cap applied to SuiteQL queries
        // so a broad query can't return thousands of rows into a chat turn.
        'timeout' => (int) env('NETSUITE_TIMEOUT', 30),
        'suiteql_max_rows' => (int) env('NETSUITE_SUITEQL_MAX_ROWS', 100),
        // REST host suffix. The full host is "<account>.<domain>" with the
        // account id lower-cased and underscores turned into dashes
        // (e.g. 1234567_SB1 -> 1234567-sb1.suitetalk.api.netsuite.com).
        'rest_domain' => env('NETSUITE_REST_DOMAIN', 'suitetalk.api.netsuite.com'),

        // OAuth 2.0 (Authorization Code Grant) — the optional second auth method.
        // The consent screen lives on the account's app domain; the token
        // endpoint on the REST (suitetalk) domain.
        'app_domain' => env('NETSUITE_APP_DOMAIN', 'app.netsuite.com'),
        // Space-separated scopes requested at consent (must match the boxes
        // ticked on the NetSuite OAuth 2.0 integration record).
        'oauth_scopes' => env('NETSUITE_OAUTH_SCOPES', 'rest_webservices'),
        // Where NetSuite sends the user back after consent. Must be HTTPS and
        // registered EXACTLY as the integration record's Redirect URI. Defaults
        // to <APP_URL>/integrations/netsuite/callback.
        'oauth_redirect' => env('NETSUITE_OAUTH_REDIRECT'),
        // Refresh the access token this many seconds before it expires.
        'oauth_refresh_leeway' => (int) env('NETSUITE_OAUTH_REFRESH_LEEWAY', 120),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),

        // Max past messages replayed to the API each turn (0 = no trim). Keeps
        // long conversations from growing context (and cost) without bound.
        'history_limit' => (int) env('ANTHROPIC_HISTORY_LIMIT', 40),

        // Beta flag for the MCP connector (native tool use via MCP servers).
        'mcp_beta' => env('ANTHROPIC_MCP_BETA', 'mcp-client-2025-04-04'),

        // Web tools — Claude's native, server-side web SEARCH + web FETCH. Lets
        // the assistant look things up online and read a URL. Only active on the
        // plain / MCP chat paths (not when Composio/NetSuite tools are in use).
        // Web fetch needs a beta header; bump it if the API version changes.
        'web_tools' => (bool) env('ANTHROPIC_WEB_TOOLS', true),
        'web_tool_max_uses' => (int) env('ANTHROPIC_WEB_TOOL_MAX_USES', 5),
        // Web search is GA. Web fetch needs a beta header — kept as its own
        // toggle so that if the beta flag ever drifts, you can disable just fetch
        // and keep search working. Bump the flag when the API version changes.
        'web_fetch' => (bool) env('ANTHROPIC_WEB_FETCH', true),
        'web_fetch_beta' => env('ANTHROPIC_WEB_FETCH_BETA', 'web-fetch-2025-09-10'),

        // Appended to the system prompt when web tools are active, so the model
        // knows it CAN browse and doesn't wrongly claim otherwise. Override with
        // a single line via ANTHROPIC_WEB_TOOLS_PROMPT.
        'web_tools_prompt' => env('ANTHROPIC_WEB_TOOLS_PROMPT', <<<'PROMPT'
            ## Web access
            You CAN search the web and read/fetch public web pages using your
            built-in web tools. When a question needs current information or a
            specific URL's contents, use them and cite what you found. Do not tell
            the user you are unable to browse the web — you can.
            PROMPT),

        // Appended to the system prompt so the model knows the user can export
        // any answer to a file. The portal renders Copy / Markdown / PDF / CSV /
        // XLSX buttons under every assistant reply — the model does not create
        // files itself; it just writes well-structured content the user downloads.
        // Override with a single line via ANTHROPIC_FILES_PROMPT.
        'files_prompt' => env('ANTHROPIC_FILES_PROMPT', <<<'PROMPT'
            ## Downloadable answers
            The user can download any of your replies as a file — there are
            Copy, Markdown (.md), and PDF buttons under every message, plus CSV and
            XLSX when your reply contains a Markdown table. So when the user asks
            for a document, report, or spreadsheet, do NOT say you cannot create
            files. Instead, write the content directly in your reply — use clear
            Markdown headings and, for tabular/spreadsheet data, a Markdown pipe
            table — and tell them to use the buttons below the message to download
            it (PDF/Markdown for documents, CSV/XLSX for tables).
            PROMPT),

        // Prompt used when the user "compacts" a conversation — Claude condenses
        // the transcript so far into a summary that stands in for the earlier
        // messages, keeping context (and cost) bounded on long chats. Override
        // with a single line via ANTHROPIC_COMPACT_PROMPT.
        'compact_prompt' => env('ANTHROPIC_COMPACT_PROMPT', <<<'PROMPT'
            You are compacting a chat transcript so the conversation can continue
            without replaying every earlier message. Write a dense, factual summary
            that preserves everything needed to keep helping the user: what they are
            trying to do, decisions made, key facts and values mentioned, answers you
            already gave, open questions, and any tool actions taken and their results.
            Use short sections or bullet points. Do not add pleasantries, do not
            address the user, and do not invent anything that was not in the transcript.
            PROMPT),

        // The assistant's persona / guardrails. Override in .env with a single
        // line via ANTHROPIC_SYSTEM_PROMPT, or edit this multi-line default.
        'system_prompt' => env('ANTHROPIC_SYSTEM_PROMPT', <<<'PROMPT'
            You are AiMe BOT, the helpful AI assistant inside the CW Global People
            customer portal. Be concise, friendly, and professional. Answer the user's
            questions directly. If you don't know something specific to their account,
            say so plainly rather than guessing. If asked your name, you are AiMe BOT.
            PROMPT),

        // Guardrail appended to the system prompt when the user has connected
        // tools (MCP servers). Makes the assistant confirm before it changes
        // external data. Set ANTHROPIC_TOOL_SAFETY=false to disable, or override
        // the text with ANTHROPIC_TOOL_SAFETY_PROMPT.
        'tool_safety' => (bool) env('ANTHROPIC_TOOL_SAFETY', true),

        'tool_safety_prompt' => env('ANTHROPIC_TOOL_SAFETY_PROMPT', <<<'PROMPT'
            ## Using connected tools safely
            You may freely READ, search, list, or fetch data with connected tools.
            But any action that CHANGES external data or state — creating, updating,
            editing, deleting, sending, moving, or overwriting — is destructive.
            Before performing a destructive action, you MUST first tell the user
            exactly what you are about to do (which tool, which records, what change),
            and ask them to confirm. Do NOT call the tool until the user has clearly
            approved that specific action in their reply. If you are unsure whether an
            action changes data, treat it as destructive and ask first. When a request
            implies several destructive steps, list them and confirm before starting.
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
