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
    Mail,
    MessageSquare,
    Plug,
    Plus,
    Power,
    Table,
    Trash2,
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

type Integration = {
    name: string;
    key: string;
    description: string;
    icon: Component;
    intro: string;
    steps: string[];
    // How this card connects: 'webhook' (automation URL), 'soon' (no backend
    // yet), or the default 'mcp' (connect as an MCP server — OAuth or token).
    connect?: 'mcp' | 'webhook' | 'soon';
    // A webhook provider that *also* exposes an MCP server, so it can be used
    // two-way (the assistant runs its workflows) as well as one-way (events).
    alsoMcp?: boolean;
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

type CatalogApp = {
    key: string;
    name: string;
    description: string;
    category: string;
    icon: string;
    connected: boolean;
    enabled: boolean;
    server_id: number | null;
};

const props = defineProps<{
    live: string[];
    webhookProviders: string[];
    connections: Record<string, Connection>;
    mcpServers: McpServer[];
    mcpCatalog: CatalogApp[];
}>();

// Catalog icon name (from config) → Lucide component.
const catalogIcons: Record<string, Component> = {
    code: Code,
    cloud: Cloud,
    workflow: Workflow,
    database: Database,
    building: Building2,
    contact: Contact,
    table: Table,
    plug: Plug,
};

function catIcon(name: string): Component {
    return catalogIcons[name] ?? Plug;
}

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
                description: 'Send chat summaries and alerts to your channels.',
                icon: MessageSquare,
                intro: 'Post AiMe BOT summaries and alerts into your Slack channels via an incoming webhook.',
                steps: [
                    'In Slack, create an app (or use an existing one) and enable Incoming Webhooks.',
                    'Add a webhook to the channel you want and copy the webhook URL.',
                    'Click Connect here and paste the webhook URL.',
                    'Pick which events (chat summaries, alerts) get posted.',
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
                    'Connect a mailbox over IMAP/SMTP, or forward mail to your unique AiMe BOT address.',
                    'Grant send permission so drafted replies can go out.',
                    'Choose which folders/labels are watched.',
                ],
            },
        ],
    },
    {
        label: 'CRM',
        blurb: 'Sync contacts, deals, and conversations with your CRM.',
        items: [
            {
                name: 'GoHighLevel (GHL)',
                key: 'ghl',
                description: 'Sync contacts, pipelines, and conversations.',
                icon: Users,
                intro: 'Sync GoHighLevel contacts and pipelines so AiMe BOT has CRM context.',
                steps: [
                    'In GoHighLevel, go to Settings → Private Integrations.',
                    'Create a token with contact and conversation scopes.',
                    'Click Connect here and paste the token (or your Location API key).',
                ],
            },
            {
                name: 'Salesforce',
                key: 'salesforce',
                description: 'Read and update records from a conversation.',
                icon: Building2,
                intro: 'Read and update Salesforce records straight from a conversation.',
                steps: [
                    'In Salesforce, create a Connected App with OAuth enabled.',
                    'Copy the consumer key and secret.',
                    'Click Connect here and authorize with your org.',
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
                    'Click Connect here and sign in with Google.',
                    'Grant read access to Drive.',
                    'Pick the folders or files to import into a project.',
                ],
            },
            {
                name: 'Google Sheets',
                key: 'google_sheets',
                description: 'Read and write spreadsheet data during a chat.',
                icon: Table,
                intro: 'Let AiMe BOT read rows and append data to a spreadsheet.',
                steps: [
                    'Click Connect here and sign in with Google.',
                    'Grant Sheets access.',
                    'Choose a spreadsheet to read from and write to.',
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
                    'Stand up an HTTPS endpoint that accepts POST requests.',
                    'Click Connect here and paste the endpoint URL and a shared secret.',
                    'Verify the signature header on your side using the secret.',
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
                    'In Zapier, add the AiMe BOT app to a new Zap.',
                    'Authenticate with an API key generated here.',
                    'Choose a trigger event and build your Zap.',
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
                    'In n8n, create a workflow and add a Webhook node.',
                    'Set the node method to POST and copy its Production URL.',
                    'Click Connect here, paste the URL, and optionally set a shared secret (sent as a header).',
                    'Activate the workflow in n8n, then use Send test to confirm delivery.',
                    'From now on, each finished chat POSTs a chat.completed event to your workflow.',
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
                    'In Make, create a scenario and add a "Custom webhook" trigger module.',
                    'Add the webhook and copy the URL Make generates.',
                    'Click Connect here, paste the URL, and optionally set a shared secret (sent as a header).',
                    'Turn the scenario on, then use Send test to confirm delivery.',
                    'From now on, each finished chat POSTs a chat.completed event to your scenario.',
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
                description:
                    'Read and update ERP records — orders, inventory, and finance.',
                icon: Boxes,
                intro: 'Read and update NetSuite ERP records — orders, inventory, and finance.',
                steps: [
                    'In NetSuite, go to Setup → Integration → Manage Integrations.',
                    'Create an integration record with Token-Based Authentication.',
                    'Generate consumer and token key/secret pairs.',
                    'Click Connect here and paste the four values.',
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
                    'Click Connect here and choose Google or Microsoft.',
                    'Authorize with read/write calendar scope.',
                    'Pick which calendar to use.',
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
                    'Click Connect here and paste a read-only connection string.',
                    'Confirm the row/time limits for sandboxed queries.',
                    'Test the connection.',
                ],
            },
            {
                name: 'Code repos',
                key: 'code_repos',
                description: 'Connect repositories for code-aware answers.',
                icon: Code,
                intro: 'Index repositories so AiMe BOT can give code-aware answers.',
                steps: [
                    'Click Connect here and install the AiMe BOT app on GitHub/GitLab.',
                    'Select which repositories to index.',
                    'Wait for the initial index to finish.',
                ],
            },
        ],
    },
];

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

// One-click app catalog (OAuth MCP servers).
function connectCatalog(key: string) {
    // Full-page nav: the route creates/finds the server then redirects to the
    // provider's own approval page (an external redirect Inertia can't follow).
    window.location.href = `/integrations/mcp/catalog/${key}/connect`;
}

function toggleCatalog(app: CatalogApp) {
    if (app.server_id === null) {
        return;
    }

    router.patch(
        `/integrations/mcp/${app.server_id}`,
        { enabled: !app.enabled },
        { preserveScroll: true },
    );
}

function removeCatalog(app: CatalogApp) {
    if (app.server_id === null) {
        return;
    }

    router.delete(`/integrations/mcp/${app.server_id}`, {
        preserveScroll: true,
    });
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

// How a catalog card connects (default: connect as an MCP server).
function connectMode(item: Integration): 'mcp' | 'webhook' | 'soon' {
    return item.connect ?? 'mcp';
}

// Open the "Add MCP server" modal prefilled for a specific tool.
function connectViaMcp(item: Integration) {
    mcpForm.clearErrors();
    mcpForm.reset();
    mcpForm.name = item.name;
    mcpForm.auth_type = 'oauth';
    mcpOpen.value = true;
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
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight">Integrations</h1>
            <p class="text-sm text-muted-foreground">
                Connect AiMe BOT to the tools you already use, grouped by what
                they do. Click a card's guide for step-by-step setup.
            </p>
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

        <!-- MCP servers (native tool connections) -->
        <section class="mb-8">
            <div class="mb-3 flex items-start justify-between gap-4">
                <div>
                    <h2
                        class="flex items-center gap-1.5 text-sm font-semibold tracking-tight"
                    >
                        <Plug class="size-4 text-brand-gold" />
                        Custom MCP servers
                        <span
                            class="rounded-full border border-brand-gold/40 bg-brand-gold/10 px-2 py-0.5 text-xs font-medium text-brand-gold"
                        >
                            Live
                        </span>
                    </h2>
                    <p class="max-w-2xl text-xs text-muted-foreground">
                        Add any Model Context Protocol server by URL (one-click
                        OAuth or a token) and AiMe BOT can use its tools
                        natively in chat. For popular apps, use the one-click
                        cards below.
                    </p>
                </div>
                <Button size="sm" class="shrink-0" @click="openMcp">
                    <Plus class="size-4" />
                    Add MCP server
                </Button>
            </div>

            <div
                v-if="mcpServers.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No custom servers yet — add one above, or connect a popular app
                in one click below.
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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

        <!-- One-click apps (OAuth MCP catalog) -->
        <section v-if="mcpCatalog.length > 0" class="mb-8">
            <div class="mb-3">
                <h2 class="text-sm font-semibold tracking-tight">
                    Apps — one-click connect
                </h2>
                <p class="text-xs text-muted-foreground">
                    Connect a tool in one click: approve on its own page, and
                    AiMe BOT can use its tools in chat. Data can move between
                    any connected apps — e.g. compare HubSpot deals against
                    Airtable.
                </p>
            </div>

            <div
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <div
                    v-for="app in mcpCatalog"
                    :key="app.key"
                    class="flex flex-col rounded-xl border bg-card p-5"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                        >
                            <component :is="catIcon(app.icon)" class="size-5" />
                        </div>
                        <span
                            v-if="app.connected"
                            class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                        >
                            <CheckCircle2 class="size-3" />
                            Connected
                        </span>
                        <span
                            v-else
                            class="rounded-full border border-brand-gold/40 bg-brand-gold/10 px-2 py-0.5 text-xs font-medium text-brand-gold"
                        >
                            OAuth
                        </span>
                    </div>

                    <p class="font-medium">{{ app.name }}</p>
                    <p class="mt-1 flex-1 text-sm text-muted-foreground">
                        {{ app.description }}
                    </p>

                    <div class="mt-4 flex items-center gap-2">
                        <template v-if="app.connected">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="connectCatalog(app.key)"
                            >
                                <Plug class="size-4" />
                                Reconnect
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="toggleCatalog(app)"
                            >
                                <Power class="size-4" />
                                {{ app.enabled ? 'Disable' : 'Enable' }}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                @click="removeCatalog(app)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </template>
                        <Button
                            v-else
                            variant="default"
                            size="sm"
                            @click="connectCatalog(app.key)"
                        >
                            <Plug class="size-4" />
                            Connect
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <section v-for="cat in categories" :key="cat.label" class="mb-8">
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
                            v-if="connection(item.key)?.connected"
                            class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                        >
                            <CheckCircle2 class="size-3" />
                            Connected
                        </span>
                        <span
                            v-else-if="connectMode(item) === 'webhook'"
                            class="rounded-full border border-brand-gold/40 bg-brand-gold/10 px-2 py-0.5 text-xs font-medium text-brand-gold"
                        >
                            Available
                        </span>
                        <span
                            v-else-if="connectMode(item) === 'mcp'"
                            class="rounded-full border border-brand-gold/40 bg-brand-gold/10 px-2 py-0.5 text-xs font-medium text-brand-gold"
                        >
                            MCP
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

                        <!-- Connect as an MCP server (OAuth or token) -->
                        <Button
                            v-else-if="connectMode(item) === 'mcp'"
                            variant="default"
                            size="sm"
                            @click="connectViaMcp(item)"
                        >
                            <Plug class="size-4" />
                            Connect
                        </Button>

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
        <DialogContent v-if="guideFor">
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

            <ol class="space-y-3">
                <li
                    v-for="(step, i) in guideFor.steps"
                    :key="i"
                    class="flex gap-3 text-sm"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold"
                    >
                        {{ i + 1 }}
                    </span>
                    <span class="pt-0.5 leading-relaxed">{{ step }}</span>
                </li>
            </ol>

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
                    v-else-if="connectMode(guideFor) === 'mcp'"
                    @click="
                        connectViaMcp(guideFor);
                        guideFor = null;
                    "
                >
                    Connect now
                </Button>
                <Button v-else variant="outline" @click="guideFor = null">
                    Close
                </Button>
            </DialogFooter>
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
