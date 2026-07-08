<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
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
    has_token: boolean;
};

const props = defineProps<{
    live: string[];
    connections: Record<string, Connection>;
    mcpServers: McpServer[];
}>();

const page = usePage();
const flash = computed(
    () => page.props.flash as { success?: string | null; error?: string | null },
);

function isLive(key: string): boolean {
    return props.live.includes(key);
}

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
                name: 'HubSpot',
                key: 'hubspot',
                description: 'Enrich chats with contact and deal context.',
                icon: Contact,
                intro: 'Pull HubSpot contact and deal context into your chats.',
                steps: [
                    'In HubSpot, go to Settings → Integrations → Private Apps.',
                    'Create a private app and grant CRM read scopes.',
                    'Copy the access token, click Connect here, and paste it.',
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

// n8n connect modal
const connectOpen = ref(false);
const n8nForm = useForm({ webhook_url: '', secret: '' });

function openConnect() {
    n8nForm.clearErrors();
    connectOpen.value = true;
}

function saveN8n() {
    n8nForm.post('/integrations/n8n', {
        preserveScroll: true,
        onSuccess: () => {
            connectOpen.value = false;
            n8nForm.reset();
        },
    });
}

function testN8n() {
    router.post('/integrations/n8n/test', {}, { preserveScroll: true });
}

function disconnect(key: string) {
    router.delete(`/integrations/${key}`, { preserveScroll: true });
}

// MCP servers (native tool connections)
const mcpOpen = ref(false);
const mcpForm = useForm({ name: '', url: '', auth_token: '' });

function openMcp() {
    mcpForm.clearErrors();
    mcpForm.reset();
    mcpOpen.value = true;
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
                        MCP servers
                        <span
                            class="rounded-full border border-brand-gold/40 bg-brand-gold/10 px-2 py-0.5 text-xs font-medium text-brand-gold"
                        >
                            Live
                        </span>
                    </h2>
                    <p class="max-w-2xl text-xs text-muted-foreground">
                        Connect a Model Context Protocol server (Slack, GitHub,
                        Notion, …) and AiMe BOT can use its tools natively in
                        chat. While any server is enabled, replies run in
                        tool-mode (non-streaming).
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
                No MCP servers yet. Add one to give the assistant real tools.
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
                        {{ hostOf(server.url)
                        }}{{ server.has_token ? ' · authenticated' : '' }}
                    </p>
                    <div class="mt-3 flex items-center gap-2">
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
                            v-else-if="isLive(item.key)"
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

                    <div class="mt-4 flex items-center gap-2">
                        <!-- Live + connected -->
                        <template
                            v-if="isLive(item.key) && connection(item.key)?.connected"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                @click="testN8n"
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

                        <!-- Live, not connected -->
                        <Button
                            v-else-if="isLive(item.key)"
                            variant="default"
                            size="sm"
                            @click="openConnect"
                        >
                            Connect
                        </Button>

                        <!-- Placeholder -->
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            disabled
                        >
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
        @update:open="(v) => { if (!v) guideFor = null; }"
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
                    v-if="isLive(guideFor.key) && !connection(guideFor.key)?.connected"
                    @click="
                        guideFor = null;
                        openConnect();
                    "
                >
                    Connect now
                </Button>
                <Button
                    v-else
                    variant="outline"
                    @click="guideFor = null"
                >
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- n8n connect modal -->
    <Dialog v-model:open="connectOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Connect n8n</DialogTitle>
                <DialogDescription>
                    Paste the Production URL of an n8n Webhook node. AiMe BOT
                    POSTs a <code>chat.completed</code> event to it after each
                    reply.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="saveN8n">
                <div class="space-y-2">
                    <Label for="n8n-url">Webhook URL</Label>
                    <Input
                        id="n8n-url"
                        v-model="n8nForm.webhook_url"
                        type="url"
                        placeholder="https://your-n8n.example.com/webhook/abc123"
                        autofocus
                    />
                    <p
                        v-if="n8nForm.errors.webhook_url"
                        class="text-sm text-destructive"
                    >
                        {{ n8nForm.errors.webhook_url }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="n8n-secret">Shared secret (optional)</Label>
                    <Input
                        id="n8n-secret"
                        v-model="n8nForm.secret"
                        placeholder="Sent as a header n8n can verify"
                    />
                    <p
                        v-if="n8nForm.errors.secret"
                        class="text-sm text-destructive"
                    >
                        {{ n8nForm.errors.secret }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="
                            n8nForm.processing ||
                            n8nForm.webhook_url.trim() === ''
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
                    available to AiMe BOT in chat. The token is stored encrypted
                    and never shown again.
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
                    <Label for="mcp-token">Authorization token (optional)</Label>
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
