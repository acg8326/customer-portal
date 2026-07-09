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
        'n8n,zapier,webhooks,make',
    ))),

    /*
    |--------------------------------------------------------------------------
    | Webhook (automation) providers
    |--------------------------------------------------------------------------
    |
    | Providers that work the same way as n8n: the user pastes an outbound
    | webhook URL (+ optional shared secret) and AiMe POSTs events to it. All
    | share the n8n timeout/secret-header settings below.
    |
    */

    'webhook_providers' => array_filter(explode(',', (string) env(
        'INTEGRATION_WEBHOOK_PROVIDERS',
        'n8n,zapier,webhooks,make',
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

    /*
    |--------------------------------------------------------------------------
    | MCP OAuth
    |--------------------------------------------------------------------------
    |
    | One-click "Connect" for MCP servers that speak OAuth 2.1. We discover the
    | authorization server (RFC 9728 / RFC 8414), self-register via Dynamic
    | Client Registration (RFC 7591) where supported, then run the
    | authorization-code + PKCE flow. Nothing here is a per-server secret —
    | those live (encrypted) in the mcp_servers table.
    |
    */

    'mcp_oauth' => [
        // Client name advertised to authorization servers during registration.
        'client_name' => env('MCP_OAUTH_CLIENT_NAME', 'CWGP-AIMe'),

        // Space-separated scopes to request. Empty = let the server decide.
        'scopes' => env('MCP_OAUTH_SCOPES', ''),

        // Seconds to wait on discovery / registration / token HTTP calls.
        'timeout' => (int) env('MCP_OAUTH_TIMEOUT', 10),

        // Refresh the access token this many seconds before it actually expires,
        // so a chat turn never fails on a token that lapses mid-request.
        'refresh_leeway' => (int) env('MCP_OAUTH_REFRESH_LEEWAY', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP catalog — one-click "Connect" apps
    |--------------------------------------------------------------------------
    |
    | Known remote MCP servers, surfaced as one-click cards on the Integrations
    | page. Clicking Connect creates the user's MCP server from this entry and
    | runs the OAuth flow (discovery → registration → PKCE → tokens). Any tool
    | not listed here can still be added by hand via "Add MCP server".
    |
    | URLs are best-effort and editable here — vendors' MCP endpoints move, and
    | a wrong URL simply fails discovery with a clear message (no crash). Fields:
    | key, name, description, category, icon (mapped to a Lucide icon on the
    | frontend), url.
    |
    */

    'mcp_catalog' => [
        [
            'key' => 'github',
            'name' => 'GitHub',
            'description' => 'Issues, PRs, and repo context as native tools.',
            'category' => 'Developer',
            'icon' => 'code',
            'url' => env('MCP_CATALOG_GITHUB_URL', 'https://api.githubcopilot.com/mcp/'),
        ],
        [
            'key' => 'notion',
            'name' => 'Notion',
            'description' => 'Search and update Notion pages and databases.',
            'category' => 'Productivity',
            'icon' => 'cloud',
            'url' => env('MCP_CATALOG_NOTION_URL', 'https://mcp.notion.com/mcp'),
        ],
        [
            'key' => 'linear',
            'name' => 'Linear',
            'description' => 'Read and manage Linear issues and projects.',
            'category' => 'Productivity',
            'icon' => 'workflow',
            'url' => env('MCP_CATALOG_LINEAR_URL', 'https://mcp.linear.app/mcp'),
        ],
        [
            'key' => 'sentry',
            'name' => 'Sentry',
            'description' => 'Investigate errors and issues from chat.',
            'category' => 'Developer',
            'icon' => 'database',
            'url' => env('MCP_CATALOG_SENTRY_URL', 'https://mcp.sentry.dev/mcp'),
        ],
        [
            'key' => 'atlassian',
            'name' => 'Atlassian (Jira/Confluence)',
            'description' => 'Jira issues and Confluence pages as tools.',
            'category' => 'Productivity',
            'icon' => 'building',
            'url' => env('MCP_CATALOG_ATLASSIAN_URL', 'https://mcp.atlassian.com/v1/mcp/authv2'),
        ],
        [
            'key' => 'asana',
            'name' => 'Asana',
            'description' => 'Tasks and projects the assistant can act on.',
            'category' => 'Productivity',
            'icon' => 'workflow',
            'url' => env('MCP_CATALOG_ASANA_URL', 'https://mcp.asana.com/sse'),
        ],
        [
            'key' => 'hubspot',
            'name' => 'HubSpot',
            'description' => 'Contacts, deals, and pipelines as native tools.',
            'category' => 'CRM & business',
            'icon' => 'contact',
            'url' => env('MCP_CATALOG_HUBSPOT_URL', 'https://mcp.hubspot.com'),
        ],
        [
            'key' => 'airtable',
            'name' => 'Airtable',
            'description' => 'Read, compare, and update Airtable bases.',
            'category' => 'CRM & business',
            'icon' => 'table',
            'url' => env('MCP_CATALOG_AIRTABLE_URL', 'https://mcp.airtable.com/mcp'),
        ],
        [
            'key' => 'stripe',
            'name' => 'Stripe',
            'description' => 'Look up customers, payments, and invoices.',
            'category' => 'CRM & business',
            'icon' => 'building',
            'url' => env('MCP_CATALOG_STRIPE_URL', 'https://mcp.stripe.com'),
        ],
        [
            'key' => 'paypal',
            'name' => 'PayPal',
            'description' => 'Invoices, payments, and transactions.',
            'category' => 'CRM & business',
            'icon' => 'building',
            'url' => env('MCP_CATALOG_PAYPAL_URL', 'https://mcp.paypal.com'),
        ],
        [
            'key' => 'intercom',
            'name' => 'Intercom',
            'description' => 'Search conversations, contacts, and articles.',
            'category' => 'CRM & business',
            'icon' => 'contact',
            'url' => env('MCP_CATALOG_INTERCOM_URL', 'https://mcp.intercom.com/mcp'),
        ],
        [
            'key' => 'vercel',
            'name' => 'Vercel',
            'description' => 'Manage projects, deployments, and logs.',
            'category' => 'Developer',
            'icon' => 'cloud',
            'url' => env('MCP_CATALOG_VERCEL_URL', 'https://mcp.vercel.com'),
        ],
    ],

];
