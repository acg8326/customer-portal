<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Blocks,
    Boxes,
    Building2,
    Calendar,
    CheckCircle2,
    Cloud,
    Code,
    Contact,
    Database,
    LayoutGrid,
    Mail,
    MessageSquare,
    Plug,
    Plus,
    Power,
    Search,
    Star,
    Table,
    Trash2,
    TriangleAlert,
    Users,
    Webhook,
    Workflow,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Integrations', href: '/integrations' }],
        fullWidth: true,
    },
});

// A guide step, rendered as a card in the guide modal: a titled header, an
// optional menu path (breadcrumb chips), body text, a checklist of boxes to
// tick, a paste-exactly code value, and info/warning callouts.
type GuideStep = {
    title: string;
    path?: string[];
    body?: string;
    checks?: string[];
    // Lead-in line rendered directly above the code block, so the value
    // always says where it goes (e.g. “Paste this URL into …”).
    codeLabel?: string;
    code?: string;
    note?: string;
    warn?: string;
};

type Integration = {
    name: string;
    key: string;
    description: string;
    icon: Component;
    intro: string;
    steps: GuideStep[];
    // How this card connects: 'webhook' (automation URL) or 'soon' (no backend
    // yet). Tools reachable through Composio set `composio` (their toolkit key)
    // instead — those connect per-user in one click.
    connect?: 'webhook' | 'soon';
    // Composio toolkit key (e.g. 'slack', 'github', 'hubspot'). When set and that
    // toolkit is configured, the card connects per-user via Composio.
    composio?: string;
    // A webhook provider that *also* exposes an MCP server, so it can be used
    // two-way (the assistant runs its workflows) as well as one-way (events).
    alsoMcp?: boolean;
    // A native (non-broker) integration built into the app — currently just
    // NetSuite over OAuth 2.0. Drives its own connect modal.
    native?: 'netsuite';
};

type Category = {
    label: string;
    blurb: string;
    items: Integration[];
};

type Connection = {
    connected: boolean;
    endpoint: string | null;
    updated_at: string | null;
};

type McpServer = {
    id: number;
    name: string;
    url: string;
    enabled: boolean;
    auth_type: 'token' | 'oauth';
    has_token: boolean;
    oauth_connected: boolean;
};

type ComposioField = {
    name: string;
    label: string;
};

type ComposioToolkit = {
    key: string;
    name: string;
    connected: boolean;
    // 'managed' = one-click (Composio owns the OAuth app); 'credentials' =
    // the user pastes their own OAuth app client id/secret (e.g. NetSuite).
    mode: string;
    // Secret credentials collected to create the auth config.
    credentialFields: ComposioField[];
    // Non-secret values collected before consent (e.g. NetSuite account id).
    fields: ComposioField[];
    // Extra OAuth scopes the user can opt into (checkboxes).
    optionalScopes: ComposioField[];
};

type Composio = {
    enabled: boolean;
    toolkits: ComposioToolkit[];
};

// Native NetSuite connection state — no secrets, only what the UI needs.
// A user can hold several accounts at once; one is the default for chats.
type NetsuiteAccount = {
    id: number;
    accountId: string;
    label: string;
    isDefault: boolean;
    authType: string; // 'tba' | 'oauth2'
    status: string;
    lastError: string | null;
};

type Netsuite = {
    enabled: boolean;
    connected: boolean;
    accounts: NetsuiteAccount[];
    // The exact redirect URI the server sends in the OAuth flow — what the
    // integration record must contain (prod: https://aime.cwglobal.ai/…).
    redirectUri: string;
};

const props = defineProps<{
    live: string[];
    webhookProviders: string[];
    connections: Record<string, Connection>;
    mcpServers: McpServer[];
    composio: Composio;
    netsuite: Netsuite;
}>();

// Whether the user has a stored NetSuite connection (feature must be enabled).
const netsuiteConnected = computed(
    () =>
        Boolean(props.netsuite?.enabled) && Boolean(props.netsuite?.connected),
);

// The redirect URI shown in the NetSuite guide + connect dialog. Comes from
// the server so it always matches what the OAuth flow will actually send.
const netsuiteRedirectUri =
    props.netsuite?.redirectUri ||
    (typeof window !== 'undefined' ? window.location.origin : '') +
        '/integrations/netsuite/callback';

// Composio toolkits keyed by their key, for quick per-card lookup.
const composioByKey = computed<Record<string, ComposioToolkit>>(() => {
    const map: Record<string, ComposioToolkit> = {};

    if (props.composio?.enabled) {
        for (const t of props.composio.toolkits) {
            map[t.key] = t;
        }
    }

    return map;
});

const page = usePage();
const flash = computed(
    () =>
        page.props.flash as { success?: string | null; error?: string | null },
);

function connection(key: string): Connection | undefined {
    return props.connections[key];
}

const categories: Category[] = [
    {
        label: 'Communication',
        blurb: 'Reach your team and customers where they already talk.',
        items: [
            {
                name: 'Slack',
                key: 'slack',
                composio: 'slack',
                description:
                    'Search messages, post to channels, and look up people.',
                icon: MessageSquare,
                intro: 'Connect your Slack account so AiMe BOT can search, read, and post on your behalf.',
                steps: [
                    {
                        title: 'Connect & approve',
                        body: 'Click Connect on this card — you’ll be sent to Slack to approve access.',
                    },
                    {
                        title: 'Pick your workspace',
                        body: 'Choose the workspace and allow the requested permissions.',
                    },
                    {
                        title: 'Done — try it in chat',
                        body: 'The card flips to Connected. Try “list my Slack channels” in chat.',
                    },
                ],
            },
            {
                name: 'Email',
                key: 'email',
                connect: 'soon',
                description:
                    'Forward emails to AiMe BOT and get drafted replies.',
                icon: Mail,
                intro: 'Turn incoming email into chats and get drafted replies back.',
                steps: [
                    {
                        title: 'Connect a mailbox',
                        body: 'Connect over IMAP/SMTP, or forward mail to your unique AiMe BOT address.',
                    },
                    {
                        title: 'Allow sending',
                        body: 'Grant send permission so drafted replies can go out.',
                    },
                    {
                        title: 'Choose what’s watched',
                        body: 'Pick which folders or labels AiMe BOT watches.',
                    },
                ],
            },
        ],
    },
    {
        label: 'CRM',
        blurb: 'Sync contacts, deals, and conversations with your CRM.',
        items: [
            {
                name: 'HubSpot',
                key: 'hubspot',
                composio: 'hubspot',
                description: 'Contacts, deals, and pipelines as native tools.',
                icon: Contact,
                intro: 'Connect your HubSpot account so AiMe BOT can read and update contacts, deals, and pipelines.',
                steps: [
                    {
                        title: 'Connect & approve',
                        body: 'Click Connect on this card and approve access on HubSpot.',
                    },
                    {
                        title: 'Choose the account',
                        body: 'Pick the HubSpot account and allow the requested scopes.',
                    },
                    {
                        title: 'Done — try it in chat',
                        body: 'The card flips to Connected — ask about your deals in chat.',
                    },
                ],
            },
            {
                name: 'GoHighLevel (GHL)',
                key: 'ghl',
                description: 'Sync contacts, pipelines, and conversations.',
                icon: Users,
                intro: 'Sync GoHighLevel contacts and pipelines so AiMe BOT has CRM context.',
                steps: [
                    {
                        title: 'Create a private integration',
                        path: ['Settings', 'Private Integrations'],
                        body: 'In GoHighLevel, create a new private integration.',
                    },
                    {
                        title: 'Scope the token',
                        body: 'Give the token contact and conversation scopes.',
                    },
                    {
                        title: 'Paste it here',
                        body: 'Click Connect on this card and paste the token (or your Location API key).',
                    },
                ],
            },
            {
                name: 'Salesforce',
                key: 'salesforce',
                description: 'Read and update records from a conversation.',
                icon: Building2,
                intro: 'Read and update Salesforce records straight from a conversation.',
                steps: [
                    {
                        title: 'Create a Connected App',
                        body: 'In Salesforce, create a Connected App with OAuth enabled.',
                    },
                    {
                        title: 'Copy the credentials',
                        body: 'Copy the consumer key and consumer secret.',
                    },
                    {
                        title: 'Authorize',
                        body: 'Click Connect on this card and authorize with your org.',
                    },
                ],
            },
        ],
    },
    {
        label: 'Files & documents',
        blurb: 'Bring your documents in as project knowledge.',
        items: [
            {
                name: 'Google Drive',
                key: 'google_drive',
                description:
                    'Pull documents from Drive into project knowledge.',
                icon: Cloud,
                intro: 'Bring Google Drive documents into a project as knowledge.',
                steps: [
                    {
                        title: 'Sign in with Google',
                        body: 'Click Connect on this card and sign in with your Google account.',
                    },
                    {
                        title: 'Grant read access',
                        body: 'Allow read access to Drive.',
                    },
                    {
                        title: 'Pick your content',
                        body: 'Choose the folders or files to import into a project.',
                    },
                ],
            },
            {
                name: 'Google Sheets',
                key: 'google_sheets',
                description: 'Read and write spreadsheet data during a chat.',
                icon: Table,
                intro: 'Let AiMe BOT read rows and append data to a spreadsheet.',
                steps: [
                    {
                        title: 'Sign in with Google',
                        body: 'Click Connect on this card and sign in with your Google account.',
                    },
                    {
                        title: 'Grant Sheets access',
                        body: 'Allow access to Google Sheets.',
                    },
                    {
                        title: 'Choose a spreadsheet',
                        body: 'Pick the spreadsheet to read from and write to.',
                    },
                ],
            },
        ],
    },
    {
        label: 'Automation',
        blurb: 'Trigger and receive events across your stack.',
        items: [
            {
                name: 'Webhooks',
                key: 'webhooks',
                connect: 'webhook',
                description:
                    'Trigger workflows when a chat or project changes.',
                icon: Webhook,
                intro: 'Receive a signed JSON payload on each event at your own endpoint.',
                steps: [
                    {
                        title: 'Stand up an endpoint',
                        body: 'Create an HTTPS endpoint that accepts POST requests.',
                    },
                    {
                        title: 'Connect it here',
                        body: 'Click Connect on this card, paste the endpoint URL, and set a shared secret.',
                    },
                    {
                        title: 'Verify the signature',
                        body: 'Check the signature header on your side using the secret.',
                    },
                ],
            },
            {
                name: 'Zapier',
                key: 'zapier',
                connect: 'webhook',
                alsoMcp: true,
                description: 'Connect AiMe BOT to 6,000+ apps, no code.',
                icon: Zap,
                intro: 'Trigger Zaps from chat and project events — no code.',
                steps: [
                    {
                        title: 'Add the app in Zapier',
                        body: 'In Zapier, add the AiMe BOT app to a new Zap.',
                    },
                    {
                        title: 'Authenticate',
                        body: 'Use an API key generated here.',
                    },
                    {
                        title: 'Build the Zap',
                        body: 'Choose a trigger event and build your workflow.',
                    },
                ],
            },
            {
                name: 'n8n',
                key: 'n8n',
                connect: 'webhook',
                alsoMcp: true,
                description:
                    'Trigger self-hosted n8n workflows from chats and projects.',
                icon: Workflow,
                intro: 'AiMe BOT POSTs a chat.completed event to your n8n Webhook node after every reply. Outbound only — no data leaves until an event fires.',
                steps: [
                    {
                        title: 'Create a Webhook node',
                        body: 'In n8n, create a workflow and add a Webhook node.',
                    },
                    {
                        title: 'Copy the Production URL',
                        body: 'Set the node method to POST and copy its Production URL.',
                    },
                    {
                        title: 'Connect it here',
                        body: 'Click Connect on this card, paste the URL, and optionally set a shared secret (sent as a header).',
                    },
                    {
                        title: 'Test the delivery',
                        body: 'Activate the workflow in n8n, then use Send test to confirm delivery.',
                        note: 'From now on, each finished chat POSTs a chat.completed event to your workflow.',
                    },
                ],
            },
            {
                name: 'Make',
                key: 'make',
                connect: 'webhook',
                alsoMcp: true,
                description: 'Trigger Make scenarios from chats and projects.',
                icon: Blocks,
                intro: 'AiMe BOT POSTs a chat.completed event to a Make Custom webhook after every reply. Outbound only — no data leaves until an event fires.',
                steps: [
                    {
                        title: 'Add a Custom webhook',
                        body: 'In Make, create a scenario and add a “Custom webhook” trigger module.',
                    },
                    {
                        title: 'Copy the URL',
                        body: 'Add the webhook and copy the URL Make generates.',
                    },
                    {
                        title: 'Connect it here',
                        body: 'Click Connect on this card, paste the URL, and optionally set a shared secret (sent as a header).',
                    },
                    {
                        title: 'Test the delivery',
                        body: 'Turn the scenario on, then use Send test to confirm delivery.',
                        note: 'From now on, each finished chat POSTs a chat.completed event to your scenario.',
                    },
                ],
            },
        ],
    },
    {
        label: 'ERP & business systems',
        blurb: 'Connect the systems that run your operations and finance.',
        items: [
            {
                name: 'NetSuite',
                key: 'netsuite',
                native: 'netsuite',
                description:
                    'Read ERP data — customers, invoices, transactions — with SuiteQL + REST.',
                icon: Boxes,
                intro: 'Connect NetSuite over OAuth 2.0 so AiMe BOT can read your ERP data with SuiteQL and the REST record API. One-time NetSuite setup (admin), then connecting is a click + consent.',
                steps: [
                    {
                        title: 'Enable Features',
                        path: [
                            'Setup',
                            'Company',
                            'Enable Features',
                            'SuiteCloud tab',
                        ],
                        body: 'On the SuiteCloud tab, tick all four boxes below, then click Save.',
                        checks: [
                            'Client SuiteScript',
                            'Server SuiteScript',
                            'REST Web Services',
                            'OAuth 2.0',
                        ],
                    },
                    {
                        title: 'Create the integration record',
                        path: [
                            'Setup',
                            'Integration',
                            'Manage Integrations',
                            'New',
                        ],
                        body: 'Everything in this step is on one screen. Give the record a name — e.g. “CWGP-AIMe” — then scroll down to the OAuth 2.0 section and tick all three boxes below.',
                        checks: [
                            'Authorization Code Grant',
                            'Scope: REST Web Services',
                            'Scope: RESTlets',
                        ],
                        codeLabel:
                            'Then paste this URL into the “Redirect URI” field:',
                        code: netsuiteRedirectUri,
                        note: 'Character-for-character — scheme, host, path, no trailing slash.',
                    },
                    {
                        title: 'Save — and copy your credentials',
                        body: 'After saving, NetSuite shows this record’s Client ID and Client Secret.',
                        warn: 'The Client Secret is shown only once. Copy both values somewhere safe now — if you lose the secret you’ll have to reset the credentials on the record.',
                    },
                    {
                        title: 'Find your Account ID',
                        path: ['Setup', 'Company', 'Company Information'],
                        body: 'Copy the “Account ID” value — e.g. 1234567, or 1234567_SB1 for a sandbox.',
                        note: 'It’s also the first part of your NetSuite URL (1234567.app.netsuite.com).',
                    },
                    {
                        title: 'Check role permissions',
                        body: 'The user who’ll approve access needs a role with the two permissions below, plus access to every record AiMe should read (e.g. Customers, Invoices, Transactions).',
                        checks: [
                            'REST Web Services',
                            'OAuth 2.0 Authorized Applications',
                        ],
                    },
                    {
                        title: 'Connect',
                        body: 'Back on this page, click Connect on the NetSuite card and paste the Account ID, Client ID, and Client Secret. You’ll be sent to NetSuite’s consent screen — approve, and you land back here, connected.',
                    },
                ],
            },
        ],
    },
    {
        label: 'Productivity & data',
        blurb: 'Schedules, databases, and code the assistant can use.',
        items: [
            {
                name: 'Calendar',
                key: 'calendar',
                description: 'Let the assistant see and schedule events.',
                icon: Calendar,
                intro: 'Let AiMe BOT see availability and create calendar events.',
                steps: [
                    {
                        title: 'Choose a provider',
                        body: 'Click Connect on this card and choose Google or Microsoft.',
                    },
                    {
                        title: 'Authorize',
                        body: 'Approve read/write calendar access.',
                    },
                    {
                        title: 'Pick a calendar',
                        body: 'Choose which calendar AiMe BOT uses.',
                    },
                ],
            },
            {
                name: 'Database',
                key: 'database',
                connect: 'soon',
                description: 'Query your own data sources securely.',
                icon: Database,
                intro: 'Query your own database read-only, in a sandbox.',
                steps: [
                    {
                        title: 'Paste a connection string',
                        body: 'Click Connect on this card and paste a read-only connection string.',
                    },
                    {
                        title: 'Confirm the limits',
                        body: 'Confirm the row and time limits for sandboxed queries.',
                    },
                    {
                        title: 'Test it',
                        body: 'Run the connection test.',
                    },
                ],
            },
            {
                name: 'Airtable',
                key: 'airtable',
                composio: 'airtable',
                description:
                    'Read and update bases, tables, and records as native tools.',
                icon: LayoutGrid,
                intro: 'Connect your Airtable account so AiMe BOT can read and update bases, tables, and records.',
                steps: [
                    {
                        title: 'Connect & approve',
                        body: 'Click Connect on this card and approve access on Airtable.',
                    },
                    {
                        title: 'Choose your bases',
                        body: 'Pick the bases or workspaces to grant access to.',
                    },
                    {
                        title: 'Done — try it in chat',
                        body: 'The card flips to Connected — try “list my Airtable bases” in chat.',
                    },
                ],
            },
            {
                name: 'GitHub',
                key: 'github',
                composio: 'github',
                description: 'Issues, PRs, and repo context as native tools.',
                icon: Code,
                intro: 'Connect your GitHub account so AiMe BOT can read and act on issues, PRs, and repositories.',
                steps: [
                    {
                        title: 'Connect & approve',
                        body: 'Click Connect on this card and approve access on GitHub.',
                    },
                    {
                        title: 'Authorize the app',
                        body: 'Authorize for your account or organization.',
                    },
                    {
                        title: 'Done — try it in chat',
                        body: 'The card flips to Connected — try “list my GitHub repos” in chat.',
                    },
                ],
            },
        ],
    },
];

// Whether the user has already linked this app (Composio or event webhook).
// Connected apps move to the "Currently connected" table and drop out of the
// card grid, so they aren't shown twice.
function isConnected(item: Integration): boolean {
    if (item.native === 'netsuite') {
        // The NetSuite card stays in the grid even when connected — it's the
        // entry point for adding ANOTHER account (multi-account support).
        return false;
    }

    return Boolean(
        composioState(item)?.connected || connection(item.key)?.connected,
    );
}

// Search — filter the catalog by app name, description, or category. Connected
// apps are always excluded from the grid (they live in the table above).
const query = ref('');

const filteredCategories = computed<Category[]>(() => {
    const q = query.value.trim().toLowerCase();

    return categories
        .map((cat) => ({
            ...cat,
            items: cat.items.filter((it) => {
                if (isConnected(it)) {
                    return false;
                }

                if (q === '') {
                    return true;
                }

                return (
                    it.name.toLowerCase().includes(q) ||
                    it.description.toLowerCase().includes(q) ||
                    cat.label.toLowerCase().includes(q)
                );
            }),
        }))
        .filter((cat) => cat.items.length > 0);
});

// Everything the user has actually connected through a card (Composio per-user
// links + event webhooks), for the "Currently connected" overview table. MCP
// servers have their own section above, so they're not duplicated here.
type ConnectedRow = {
    item: Integration;
    category: string;
    mode: 'composio' | 'webhook' | 'netsuite';
    detail: string | null;
    // One row per linked NetSuite account.
    account?: NetsuiteAccount;
};

const connectedRows = computed<ConnectedRow[]>(() => {
    const rows: ConnectedRow[] = [];

    for (const cat of categories) {
        for (const item of cat.items) {
            if (item.native === 'netsuite') {
                for (const account of props.netsuite?.accounts ?? []) {
                    if (account.status === 'pending') {
                        continue; // awaiting OAuth consent — not live yet
                    }

                    rows.push({
                        item,
                        category: cat.label,
                        mode: 'netsuite',
                        account,
                        detail:
                            `${account.label} · Account ${account.accountId}` +
                            ` · ${account.authType === 'oauth2' ? 'OAuth 2.0' : 'TBA'}` +
                            (account.isDefault ? ' · default' : '') +
                            (account.status === 'error'
                                ? ' · needs attention'
                                : ''),
                    });
                }
            } else if (composioState(item)?.connected) {
                rows.push({
                    item,
                    category: cat.label,
                    mode: 'composio',
                    detail: null,
                });
            } else if (connection(item.key)?.connected) {
                rows.push({
                    item,
                    category: cat.label,
                    mode: 'webhook',
                    detail: connection(item.key)?.endpoint ?? null,
                });
            }
        }
    }

    return rows;
});

// Guide modal
const guideFor = ref<Integration | null>(null);

function openGuide(item: Integration) {
    guideFor.value = item;
}

// Webhook (automation) connect modal — shared by n8n, Zapier, and Webhooks.
const connectOpen = ref(false);
const connectProvider = ref('n8n');
const webhookForm = useForm({ webhook_url: '', secret: '' });

const connectProviderLabel = computed(
    () =>
        connectProvider.value.charAt(0).toUpperCase() +
        connectProvider.value.slice(1),
);

function openConnect(provider: string) {
    connectProvider.value = provider;
    webhookForm.clearErrors();
    webhookForm.reset();
    connectOpen.value = true;
}

function saveWebhook() {
    webhookForm.post(`/integrations/webhook/${connectProvider.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            connectOpen.value = false;
            webhookForm.reset();
        },
    });
}

function testWebhook(provider: string) {
    router.post(
        `/integrations/webhook/${provider}/test`,
        {},
        { preserveScroll: true },
    );
}

function disconnect(key: string) {
    router.delete(`/integrations/${key}`, { preserveScroll: true });
}

// MCP servers (native tool connections)
const mcpOpen = ref(false);
const mcpForm = useForm<{
    name: string;
    url: string;
    auth_type: 'token' | 'oauth';
    auth_token: string;
}>({ name: '', url: '', auth_type: 'oauth', auth_token: '' });

function openMcp() {
    mcpForm.clearErrors();
    mcpForm.reset();
    mcpOpen.value = true;
}

// Open the "Add MCP server" modal prefilled for a specific tool (used by the
// automation providers that also expose an MCP server — two-way "Use as tools").
function connectViaMcp(item: Integration) {
    mcpForm.clearErrors();
    mcpForm.reset();
    mcpForm.name = item.name;
    mcpForm.auth_type = 'oauth';
    mcpOpen.value = true;
}

// How a card connects: a configured Composio toolkit → 'composio'; an automation
// webhook → 'webhook'; otherwise it's a not-yet-built placeholder → 'soon'.
function connectMode(
    item: Integration,
): 'composio' | 'webhook' | 'netsuite' | 'soon' {
    if (item.native === 'netsuite' && props.netsuite?.enabled) {
        return 'netsuite';
    }

    if (item.composio && composioByKey.value[item.composio]) {
        return 'composio';
    }

    return item.connect === 'webhook' ? 'webhook' : 'soon';
}

// The Composio connection state for a card (undefined if not a Composio tool).
function composioState(item: Integration): ComposioToolkit | undefined {
    return item.composio ? composioByKey.value[item.composio] : undefined;
}

function connectMcp(server: McpServer) {
    // Full-page navigation: the connect route redirects to the provider's
    // own authorization page (an external redirect Inertia's XHR can't follow).
    window.location.href = `/integrations/mcp/${server.id}/oauth/connect`;
}

function saveMcp() {
    mcpForm.post('/integrations/mcp', {
        preserveScroll: true,
        onSuccess: () => {
            mcpOpen.value = false;
            mcpForm.reset();
        },
    });
}

function toggleMcp(server: McpServer) {
    router.patch(
        `/integrations/mcp/${server.id}`,
        { enabled: !server.enabled },
        { preserveScroll: true },
    );
}

function removeMcp(server: McpServer) {
    router.delete(`/integrations/mcp/${server.id}`, { preserveScroll: true });
}

// Composio-brokered per-user connections. Managed toolkits (Composio owns the
// OAuth app) connect in one click — a full-page redirect to the consent screen.
// Credentials toolkits (e.g. NetSuite) first collect the user's own OAuth app
// client id/secret + an account id in a modal; we POST those, the server creates
// the auth config and returns the consent URL to navigate to.
const composioConnectToolkit = ref<ComposioToolkit | null>(null);
const composioForm = ref<Record<string, string>>({});
const composioScopes = ref<Record<string, boolean>>({});
const composioSubmitting = ref(false);
const composioError = ref<string | null>(null);

const composioFormValid = computed(() => {
    const tk = composioConnectToolkit.value;

    if (tk === null) {
        return false;
    }

    return [...tk.credentialFields, ...tk.fields].every(
        (f) => (composioForm.value[f.name] ?? '').trim() !== '',
    );
});

function connectComposio(key: string) {
    const tk = composioByKey.value[key];

    if (tk && tk.mode === 'credentials') {
        composioForm.value = Object.fromEntries(
            [...tk.credentialFields, ...tk.fields].map((f) => [f.name, '']),
        );
        composioScopes.value = Object.fromEntries(
            tk.optionalScopes.map((s) => [s.name, false]),
        );
        composioError.value = null;
        composioConnectToolkit.value = tk;

        return;
    }

    window.location.href = `/integrations/composio/${key}/connect`;
}

function readCookie(name: string): string {
    const match = document.cookie.match(
        new RegExp('(^|; )' + name + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[2]) : '';
}

async function submitComposioConnect() {
    const tk = composioConnectToolkit.value;

    if (!tk || !composioFormValid.value) {
        return;
    }

    composioSubmitting.value = true;
    composioError.value = null;

    const payload: Record<string, unknown> = {};

    for (const f of [...tk.credentialFields, ...tk.fields]) {
        payload[f.name] = (composioForm.value[f.name] ?? '').trim();
    }

    payload.scopes = Object.keys(composioScopes.value).filter(
        (k) => composioScopes.value[k],
    );

    try {
        const res = await fetch(`/integrations/composio/${tk.key}/connect`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));

        if (!res.ok || typeof data.redirect_url !== 'string') {
            composioError.value =
                data.message ?? 'Could not start the connection. Please retry.';

            return;
        }

        window.location.href = data.redirect_url;
    } catch {
        composioError.value = 'Could not reach the server. Please retry.';
    } finally {
        composioSubmitting.value = false;
    }
}

function disconnectComposio(key: string) {
    router.delete(`/integrations/composio/${key}`, { preserveScroll: true });
}

// Native NetSuite (TBA) connect modal — collects the five values from the
// user's OAuth 2.0 Client ID/Secret + Account ID, POSTs them, and the server
// redirects the browser to NetSuite's consent screen. (Legacy TBA connections
// made before the OAuth2-only switch keep working server-side.)
type NetsuiteForm = {
    account_id: string;
    label: string;
    client_id: string;
    client_secret: string;
};

type NetsuiteField = {
    name: keyof NetsuiteForm;
    label: string;
    secret: boolean;
};

const netsuiteOpen = ref(false);
const netsuiteSubmitting = ref(false);
const netsuiteError = ref<string | null>(null);
const netsuiteForm = ref<NetsuiteForm>({
    account_id: '',
    label: '',
    client_id: '',
    client_secret: '',
});

// The label is optional — it names the account in chat ("Client A") when the
// user connects more than one.
const netsuiteFields: NetsuiteField[] = [
    { name: 'account_id', label: 'Account ID', secret: false },
    { name: 'client_id', label: 'Client ID', secret: false },
    { name: 'client_secret', label: 'Client Secret', secret: true },
];

const netsuiteFormValid = computed(() =>
    netsuiteFields.every((f) => netsuiteForm.value[f.name].trim() !== ''),
);

// Open the connect dialog: with an account → reconnect it (prefilled); with
// null → add a new account (blank form).
function openNetsuiteFor(account: NetsuiteAccount | null) {
    netsuiteForm.value = {
        account_id: account?.accountId ?? '',
        label:
            account && account.label !== account.accountId ? account.label : '',
        client_id: '',
        client_secret: '',
    };
    netsuiteError.value = null;
    netsuiteOpen.value = true;
}

async function submitNetsuite() {
    if (!netsuiteFormValid.value) {
        return;
    }

    netsuiteSubmitting.value = true;
    netsuiteError.value = null;

    const payload: Record<string, string> = {
        auth_type: 'oauth2',
        label: netsuiteForm.value.label.trim(),
    };

    for (const f of netsuiteFields) {
        payload[f.name] = netsuiteForm.value[f.name].trim();
    }

    try {
        const res = await fetch('/integrations/netsuite/connect', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            netsuiteError.value =
                data.message ??
                (data.errors
                    ? String(Object.values(data.errors)[0])
                    : 'Could not connect. Check the credentials and try again.');

            return;
        }

        // OAuth 2.0: the server returns a consent URL to redirect the browser to.
        if (typeof data.redirect_url === 'string') {
            window.location.href = data.redirect_url;

            return;
        }

        netsuiteOpen.value = false;
        // Refresh so the connected table + card reflect the new connection.
        router.reload({ only: ['netsuite'] });
    } catch {
        netsuiteError.value = 'Could not reach the server. Please retry.';
    } finally {
        netsuiteSubmitting.value = false;
    }
}

function disconnectNetsuite(id: number) {
    router.delete(`/integrations/netsuite/${id}`, { preserveScroll: true });
}

function makeDefaultNetsuite(id: number) {
    router.patch(
        `/integrations/netsuite/${id}/default`,
        {},
        { preserveScroll: true },
    );
}

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}
</script>

<template>
    <Head title="Integrations" />

    <div class="w-full p-6">
        <div
            class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Integrations
                </h1>
                <p class="text-sm text-muted-foreground">
                    Connect AiMe BOT to the tools you already use, grouped by
                    what they do. Click a card's guide for step-by-step setup.
                </p>
            </div>
            <div class="relative w-full sm:w-64 sm:shrink-0">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    type="search"
                    placeholder="Search integrations…"
                    class="pl-9"
                />
            </div>
        </div>

        <!-- Flash -->
        <div
            v-if="flash.success"
            class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
        >
            {{ flash.success }}
        </div>
        <div
            v-if="flash.error"
            class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ flash.error }}
        </div>

        <!-- Currently connected — a quick overview of everything the user has
             linked through a card (MCP servers have their own section below). -->
        <section v-if="connectedRows.length > 0" class="mb-8">
            <div class="mb-3">
                <h2
                    class="flex items-center gap-1.5 text-sm font-semibold tracking-tight"
                >
                    <CheckCircle2 class="size-4 text-emerald-500" />
                    Currently connected apps
                </h2>
                <p class="text-xs text-muted-foreground">
                    Apps you've linked to your account — available to AiMe BOT
                    in chat right now.
                </p>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[38rem] text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th class="px-4 py-2.5 font-medium">App</th>
                            <th class="px-4 py-2.5 font-medium">Category</th>
                            <th class="px-4 py-2.5 font-medium">Details</th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in connectedRows"
                            :key="
                                row.item.key +
                                (row.account ? `-${row.account.id}` : '')
                            "
                            class="border-b last:border-0"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                                    >
                                        <component
                                            :is="row.item.icon"
                                            class="size-4"
                                        />
                                    </span>
                                    <span class="font-medium">{{
                                        row.item.name
                                    }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ row.category }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="block max-w-[22rem] truncate text-xs text-muted-foreground"
                                    :title="row.detail ?? row.item.description"
                                    >{{
                                        row.detail ?? row.item.description
                                    }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <template v-if="row.mode === 'composio'">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="
                                                connectComposio(
                                                    row.item.composio!,
                                                )
                                            "
                                        >
                                            Reconnect
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-muted-foreground"
                                            @click="
                                                disconnectComposio(
                                                    row.item.composio!,
                                                )
                                            "
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </template>
                                    <template
                                        v-else-if="row.mode === 'netsuite'"
                                    >
                                        <Button
                                            v-if="!row.account!.isDefault"
                                            variant="ghost"
                                            size="sm"
                                            class="text-muted-foreground"
                                            title="Make this the default account for new chats"
                                            @click="
                                                makeDefaultNetsuite(
                                                    row.account!.id,
                                                )
                                            "
                                        >
                                            <Star class="size-4" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="
                                                openNetsuiteFor(row.account!)
                                            "
                                        >
                                            Reconnect
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-muted-foreground"
                                            @click="
                                                disconnectNetsuite(
                                                    row.account!.id,
                                                )
                                            "
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </template>
                                    <template v-else>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="testWebhook(row.item.key)"
                                        >
                                            Send test
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-muted-foreground"
                                            @click="disconnect(row.item.key)"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Advanced: connect an MCP server directly (no broker) -->
        <section class="mb-8">
            <div class="mb-3 flex items-start justify-between gap-4">
                <div>
                    <h2
                        class="flex items-center gap-1.5 text-sm font-semibold tracking-tight"
                    >
                        <Plug class="size-4 text-brand-gold" />
                        Connect a server directly
                    </h2>
                    <p class="max-w-2xl text-xs text-muted-foreground">
                        Point AiMe BOT straight at a Model Context Protocol
                        server by URL (one-click OAuth or a token) — no broker
                        in between. Use this for self-hosted or sensitive tools
                        you don't want routed through a third party.
                    </p>
                </div>
                <Button size="sm" class="shrink-0" @click="openMcp">
                    <Plus class="size-4" />
                    Add MCP server
                </Button>
            </div>

            <div
                v-if="mcpServers.length > 0"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="server in mcpServers"
                    :key="server.id"
                    class="flex flex-col rounded-xl border bg-card p-4"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                        >
                            <Plug class="size-4" />
                        </div>
                        <span
                            class="rounded-full border px-2 py-0.5 text-xs font-medium"
                            :class="
                                server.enabled
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                    : 'border-border bg-muted/60 text-muted-foreground'
                            "
                        >
                            {{ server.enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <p class="truncate font-medium">{{ server.name }}</p>
                    <p
                        class="mt-0.5 flex-1 truncate text-xs text-muted-foreground"
                        :title="server.url"
                    >
                        {{ hostOf(server.url) }}
                        <template v-if="server.auth_type === 'oauth'">{{
                            server.oauth_connected
                                ? ' · OAuth connected'
                                : ' · OAuth — not connected'
                        }}</template>
                        <template v-else-if="server.has_token">
                            · authenticated</template
                        >
                    </p>
                    <div class="mt-3 flex items-center gap-2">
                        <Button
                            v-if="
                                server.auth_type === 'oauth' &&
                                !server.oauth_connected
                            "
                            size="sm"
                            @click="connectMcp(server)"
                        >
                            <Plug class="size-4" />
                            Connect
                        </Button>
                        <Button
                            v-else-if="server.auth_type === 'oauth'"
                            variant="outline"
                            size="sm"
                            @click="connectMcp(server)"
                        >
                            <Plug class="size-4" />
                            Reconnect
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="toggleMcp(server)"
                        >
                            <Power class="size-4" />
                            {{ server.enabled ? 'Disable' : 'Enable' }}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            @click="removeMcp(server)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <p
            v-if="filteredCategories.length === 0 && query.trim() !== ''"
            class="rounded-xl border border-dashed bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground"
        >
            No integrations match “{{ query }}”.
        </p>
        <p
            v-else-if="filteredCategories.length === 0"
            class="rounded-xl border border-dashed bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground"
        >
            Everything available is connected — see the table above.
        </p>

        <section
            v-for="cat in filteredCategories"
            :key="cat.label"
            class="mb-8"
        >
            <div class="mb-3">
                <h2 class="text-sm font-semibold tracking-tight">
                    {{ cat.label }}
                </h2>
                <p class="text-xs text-muted-foreground">{{ cat.blurb }}</p>
            </div>

            <div
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <div
                    v-for="item in cat.items"
                    :key="item.key"
                    class="flex flex-col rounded-xl border bg-card p-5"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                        >
                            <component :is="item.icon" class="size-5" />
                        </div>
                        <span
                            v-if="
                                connection(item.key)?.connected ||
                                composioState(item)?.connected
                            "
                            class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                        >
                            <CheckCircle2 class="size-3" />
                            Connected
                        </span>
                        <span
                            v-else-if="connectMode(item) !== 'soon'"
                            class="rounded-full border border-brand-gold/40 bg-brand-gold/10 px-2 py-0.5 text-xs font-medium text-brand-gold"
                        >
                            Available
                        </span>
                        <span
                            v-else
                            class="rounded-full border border-border bg-muted/60 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                        >
                            Coming soon
                        </span>
                    </div>

                    <p class="font-medium">{{ item.name }}</p>
                    <p class="mt-1 flex-1 text-sm text-muted-foreground">
                        {{ item.description }}
                    </p>

                    <p
                        v-if="connection(item.key)?.endpoint"
                        class="mt-2 truncate text-xs text-muted-foreground"
                        :title="connection(item.key)?.endpoint ?? ''"
                    >
                        → {{ connection(item.key)?.endpoint }}
                    </p>

                    <!-- Automation providers that also expose an MCP server can
                         connect two ways — spell it out so it isn't confusing. -->
                    <p
                        v-if="item.alsoMcp"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        <span class="font-medium">Use as tools</span> = the
                        assistant runs your workflows (two-way).
                        <span class="font-medium">Events webhook</span> =
                        {{ item.name }} is notified after each chat (one-way).
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <template v-if="connectMode(item) === 'webhook'">
                            <!-- Two-way tools (providers that also expose MCP) -->
                            <Button
                                v-if="item.alsoMcp"
                                variant="default"
                                size="sm"
                                @click="connectViaMcp(item)"
                            >
                                <Plug class="size-4" />
                                Use as tools
                            </Button>

                            <!-- One-way events webhook -->
                            <template v-if="connection(item.key)?.connected">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="testWebhook(item.key)"
                                >
                                    Send test
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    @click="disconnect(item.key)"
                                >
                                    Disconnect
                                </Button>
                            </template>
                            <Button
                                v-else
                                :variant="item.alsoMcp ? 'outline' : 'default'"
                                size="sm"
                                @click="openConnect(item.key)"
                            >
                                {{
                                    item.alsoMcp ? 'Events webhook' : 'Connect'
                                }}
                            </Button>
                        </template>

                        <!-- One-click connect via Composio (per-user) -->
                        <template v-else-if="connectMode(item) === 'composio'">
                            <Button
                                v-if="!composioState(item)?.connected"
                                variant="default"
                                size="sm"
                                @click="connectComposio(item.composio!)"
                            >
                                <Plug class="size-4" />
                                Connect
                            </Button>
                            <template v-else>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="connectComposio(item.composio!)"
                                >
                                    <Plug class="size-4" />
                                    Reconnect
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    @click="disconnectComposio(item.composio!)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </template>
                        </template>

                        <!-- Native NetSuite — the card stays visible so more
                             accounts can be added after the first -->
                        <template v-else-if="connectMode(item) === 'netsuite'">
                            <Button
                                variant="default"
                                size="sm"
                                @click="openNetsuiteFor(null)"
                            >
                                <Plug class="size-4" />
                                {{
                                    netsuiteConnected
                                        ? 'Add account'
                                        : 'Connect'
                                }}
                            </Button>
                        </template>

                        <!-- No backend yet -->
                        <Button v-else variant="outline" size="sm" disabled>
                            Connect
                        </Button>

                        <button
                            type="button"
                            class="text-xs font-medium text-brand-gold hover:underline"
                            @click="openGuide(item)"
                        >
                            Setup guide
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Step-by-step guide modal -->
    <Dialog
        :open="guideFor !== null"
        @update:open="
            (v) => {
                if (!v) guideFor = null;
            }
        "
    >
        <DialogContent v-if="guideFor" class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                    >
                        <component :is="guideFor.icon" class="size-4" />
                    </span>
                    Connect {{ guideFor.name }}
                </DialogTitle>
                <DialogDescription>{{ guideFor.intro }}</DialogDescription>
            </DialogHeader>

            <!-- Scrolls when a guide has many steps, so the modal never runs off
                 the screen and the header/footer stay put. -->
            <div class="max-h-[60vh] space-y-4 overflow-y-auto pr-1">
                <div class="space-y-3">
                    <div
                        v-for="(step, i) in guideFor.steps"
                        :key="i"
                        class="overflow-hidden rounded-xl border"
                    >
                        <div
                            class="flex items-center gap-3 border-b bg-muted/40 px-4 py-2.5"
                        >
                            <span
                                class="flex size-6 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-xs font-bold text-white"
                            >
                                {{ i + 1 }}
                            </span>
                            <span class="text-sm font-semibold">{{
                                step.title
                            }}</span>
                        </div>
                        <div class="space-y-3 px-4 py-3 text-sm">
                            <div
                                v-if="step.path"
                                class="flex flex-wrap items-center gap-1.5 text-xs"
                            >
                                <template
                                    v-for="(seg, j) in step.path"
                                    :key="j"
                                >
                                    <span
                                        v-if="j > 0"
                                        class="text-muted-foreground"
                                        >→</span
                                    >
                                    <span
                                        class="rounded-md bg-brand-navy/5 px-2 py-0.5 font-medium text-brand-navy dark:bg-brand-gold/10 dark:text-brand-gold"
                                        >{{ seg }}</span
                                    >
                                </template>
                            </div>
                            <p
                                v-if="step.body"
                                class="leading-relaxed text-muted-foreground"
                            >
                                {{ step.body }}
                            </p>
                            <ul
                                v-if="step.checks"
                                class="grid gap-1.5 sm:grid-cols-2"
                            >
                                <li
                                    v-for="c in step.checks"
                                    :key="c"
                                    class="flex items-center gap-2"
                                >
                                    <CheckCircle2
                                        class="size-4 shrink-0 text-emerald-500"
                                    />
                                    <span class="font-medium">{{ c }}</span>
                                </li>
                            </ul>
                            <p
                                v-if="step.codeLabel"
                                class="leading-relaxed font-medium"
                            >
                                {{ step.codeLabel }}
                            </p>
                            <code
                                v-if="step.code"
                                class="block overflow-x-auto rounded-lg bg-brand-navy px-3 py-2 font-mono text-xs whitespace-nowrap text-brand-gold"
                                >{{ step.code }}</code
                            >
                            <p
                                v-if="step.note"
                                class="text-xs text-muted-foreground"
                            >
                                {{ step.note }}
                            </p>
                            <div
                                v-if="step.warn"
                                class="flex gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs leading-relaxed text-amber-700 dark:text-amber-400"
                            >
                                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                                <span>{{ step.warn }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button
                    v-if="
                        connectMode(guideFor) === 'webhook' &&
                        !connection(guideFor.key)?.connected
                    "
                    @click="
                        openConnect(guideFor.key);
                        guideFor = null;
                    "
                >
                    Connect now
                </Button>
                <Button
                    v-else-if="
                        connectMode(guideFor) === 'composio' &&
                        !composioState(guideFor)?.connected
                    "
                    @click="
                        connectComposio(guideFor.composio!);
                        guideFor = null;
                    "
                >
                    Connect now
                </Button>
                <Button
                    v-else-if="connectMode(guideFor) === 'netsuite'"
                    @click="
                        openNetsuiteFor(null);
                        guideFor = null;
                    "
                >
                    {{ netsuiteConnected ? 'Add account' : 'Connect now' }}
                </Button>
                <Button v-else variant="outline" @click="guideFor = null">
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Composio credentials modal (bring-your-own OAuth app, e.g. NetSuite) -->
    <Dialog
        :open="composioConnectToolkit !== null"
        @update:open="
            (v) => {
                if (!v) composioConnectToolkit = null;
            }
        "
    >
        <DialogContent v-if="composioConnectToolkit" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Connect {{ composioConnectToolkit.name }}</DialogTitle
                >
                <DialogDescription>
                    Enter the OAuth2 credentials from your
                    {{ composioConnectToolkit.name }} integration record, then
                    continue to authorize access. Secrets are sent securely and
                    stored by Composio, not in your browser.
                </DialogDescription>
            </DialogHeader>

            <form
                class="max-h-[60vh] space-y-4 overflow-y-auto pr-1"
                @submit.prevent="submitComposioConnect"
            >
                <div
                    v-for="field in composioConnectToolkit.credentialFields"
                    :key="field.name"
                    class="space-y-2"
                >
                    <Label :for="`composio-${field.name}`">{{
                        field.label
                    }}</Label>
                    <Input
                        :id="`composio-${field.name}`"
                        v-model="composioForm[field.name]"
                        :type="
                            field.name === 'client_secret' ? 'password' : 'text'
                        "
                        :placeholder="field.label"
                        autocomplete="off"
                    />
                </div>

                <div
                    v-for="field in composioConnectToolkit.fields"
                    :key="field.name"
                    class="space-y-2"
                >
                    <Label :for="`composio-${field.name}`">{{
                        field.label
                    }}</Label>
                    <Input
                        :id="`composio-${field.name}`"
                        v-model="composioForm[field.name]"
                        :placeholder="
                            field.name === 'subdomain'
                                ? 'e.g. 1234567 or 1234567-sb1 (sandbox)'
                                : field.label
                        "
                    />
                    <p
                        v-if="field.name === 'subdomain'"
                        class="text-xs text-muted-foreground"
                    >
                        The part of your NetSuite URL before
                        <code>.app.netsuite.com</code> (sandboxes include the
                        <code>-sb1</code> suffix).
                    </p>
                </div>

                <label
                    v-for="scope in composioConnectToolkit.optionalScopes"
                    :key="scope.name"
                    class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-3 text-sm"
                >
                    <input
                        v-model="composioScopes[scope.name]"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span>{{ scope.label }}</span>
                </label>

                <p v-if="composioError" class="text-sm text-destructive">
                    {{ composioError }}
                </p>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="!composioFormValid || composioSubmitting"
                    >
                        <Plug class="size-4" />
                        {{ composioSubmitting ? 'Connecting…' : 'Continue' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- NetSuite native (OAuth 2.0) connect modal -->
    <Dialog v-model:open="netsuiteOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Connect NetSuite</DialogTitle>
                <DialogDescription>
                    Paste your integration record's OAuth 2.0 credentials —
                    you'll be sent to NetSuite to approve access, then returned
                    here. Secrets are encrypted on the server and never shown
                    again.
                </DialogDescription>
            </DialogHeader>

            <form
                class="max-h-[60vh] space-y-4 overflow-y-auto pr-1"
                @submit.prevent="submitNetsuite"
            >
                <p class="text-xs leading-relaxed text-muted-foreground">
                    The integration record's
                    <span class="font-semibold text-foreground"
                        >Redirect URI</span
                    >
                    must be exactly
                    <code
                        class="rounded-md bg-brand-gold/10 px-1.5 py-0.5 font-semibold break-all text-brand-gold"
                        >{{ netsuiteRedirectUri }}</code
                    >
                    — see the Setup guide on the NetSuite card.
                </p>

                <div
                    v-for="field in netsuiteFields"
                    :key="field.name"
                    class="space-y-2"
                >
                    <Label :for="`netsuite-${field.name}`">{{
                        field.label
                    }}</Label>
                    <Input
                        :id="`netsuite-${field.name}`"
                        v-model="netsuiteForm[field.name]"
                        :type="field.secret ? 'password' : 'text'"
                        :placeholder="
                            field.name === 'account_id'
                                ? 'e.g. 1234567 or 1234567_SB1 (sandbox)'
                                : field.label
                        "
                        autocomplete="off"
                    />
                    <p
                        v-if="field.name === 'account_id'"
                        class="text-xs text-muted-foreground"
                    >
                        Setup → Company → Company Information → “Account ID”.
                        Sandboxes include the <code>_SB1</code> suffix.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="netsuite-label">Label (optional)</Label>
                    <Input
                        id="netsuite-label"
                        v-model="netsuiteForm.label"
                        type="text"
                        maxlength="60"
                        placeholder="e.g. Client A, Production, Sandbox"
                        autocomplete="off"
                    />
                    <p class="text-xs text-muted-foreground">
                        Names this account when you connect more than one — it's
                        what the chat's account picker and activity indicator
                        show.
                    </p>
                </div>

                <p v-if="netsuiteError" class="text-sm text-destructive">
                    {{ netsuiteError }}
                </p>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="!netsuiteFormValid || netsuiteSubmitting"
                    >
                        <Plug class="size-4" />
                        {{
                            netsuiteSubmitting
                                ? 'Redirecting…'
                                : 'Continue to NetSuite'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- n8n connect modal -->
    <Dialog v-model:open="connectOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Connect {{ connectProviderLabel }}</DialogTitle>
                <DialogDescription>
                    Paste the webhook URL. AiMe BOT POSTs a
                    <code>chat.completed</code> event to it after each reply
                    (outbound only — nothing is sent until an event fires).
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="saveWebhook">
                <div class="space-y-2">
                    <Label for="webhook-url">Webhook URL</Label>
                    <Input
                        id="webhook-url"
                        v-model="webhookForm.webhook_url"
                        type="url"
                        placeholder="https://your-endpoint.example.com/webhook/abc123"
                        autofocus
                    />
                    <p
                        v-if="webhookForm.errors.webhook_url"
                        class="text-sm text-destructive"
                    >
                        {{ webhookForm.errors.webhook_url }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="webhook-secret">Shared secret (optional)</Label>
                    <Input
                        id="webhook-secret"
                        v-model="webhookForm.secret"
                        placeholder="Sent as a header you can verify"
                    />
                    <p
                        v-if="webhookForm.errors.secret"
                        class="text-sm text-destructive"
                    >
                        {{ webhookForm.errors.secret }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="
                            webhookForm.processing ||
                            webhookForm.webhook_url.trim() === ''
                        "
                    >
                        Save connection
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Add MCP server modal -->
    <Dialog v-model:open="mcpOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add MCP server</DialogTitle>
                <DialogDescription>
                    Paste the URL of a remote MCP server. Its tools become
                    available to AiMe BOT in chat. Choose one-click OAuth (you
                    approve on the server's own page) or paste a token. Secrets
                    are stored encrypted and never shown again.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="saveMcp">
                <div class="space-y-2">
                    <Label for="mcp-name">Name</Label>
                    <Input
                        id="mcp-name"
                        v-model="mcpForm.name"
                        placeholder="e.g. GitHub"
                        autofocus
                    />
                    <p
                        v-if="mcpForm.errors.name"
                        class="text-sm text-destructive"
                    >
                        {{ mcpForm.errors.name }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="mcp-url">Server URL</Label>
                    <Input
                        id="mcp-url"
                        v-model="mcpForm.url"
                        type="url"
                        placeholder="https://mcp.example.com/sse"
                    />
                    <p
                        v-if="mcpForm.errors.url"
                        class="text-sm text-destructive"
                    >
                        {{ mcpForm.errors.url }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label>Authentication</Label>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                mcpForm.auth_type === 'oauth'
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="mcpForm.auth_type = 'oauth'"
                        >
                            One-click OAuth
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                mcpForm.auth_type === 'token'
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="mcpForm.auth_type = 'token'"
                        >
                            Paste a token
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="mcpForm.auth_type === 'oauth'">
                            You'll be sent to the server to approve access after
                            adding it. Works with servers that support OAuth.
                        </template>
                        <template v-else>
                            Use this for servers that need a static bearer token
                            (or none at all).
                        </template>
                    </p>
                </div>
                <div v-if="mcpForm.auth_type === 'token'" class="space-y-2">
                    <Label for="mcp-token"
                        >Authorization token (optional)</Label
                    >
                    <Input
                        id="mcp-token"
                        v-model="mcpForm.auth_token"
                        placeholder="Bearer token the MCP server requires"
                    />
                    <p
                        v-if="mcpForm.errors.auth_token"
                        class="text-sm text-destructive"
                    >
                        {{ mcpForm.errors.auth_token }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="
                            mcpForm.processing ||
                            mcpForm.name.trim() === '' ||
                            mcpForm.url.trim() === ''
                        "
                    >
                        Connect server
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
