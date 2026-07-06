<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    Calendar,
    Cloud,
    Code,
    Contact,
    Database,
    Mail,
    MessageSquare,
    Table,
    Users,
    Webhook,
    Workflow,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Integrations', href: '/integrations' }],
        fullWidth: true,
    },
});

type Integration = {
    name: string;
    description: string;
    icon: Component;
};

type Category = {
    label: string;
    blurb: string;
    items: Integration[];
};

const categories: Category[] = [
    {
        label: 'Communication',
        blurb: 'Reach your team and customers where they already talk.',
        items: [
            {
                name: 'Slack',
                description: 'Send chat summaries and alerts to your channels.',
                icon: MessageSquare,
            },
            {
                name: 'Email',
                description:
                    'Forward emails to AiMe BOT and get drafted replies.',
                icon: Mail,
            },
        ],
    },
    {
        label: 'CRM',
        blurb: 'Sync contacts, deals, and conversations with your CRM.',
        items: [
            {
                name: 'GoHighLevel (GHL)',
                description: 'Sync contacts, pipelines, and conversations.',
                icon: Users,
            },
            {
                name: 'HubSpot',
                description: 'Enrich chats with contact and deal context.',
                icon: Contact,
            },
            {
                name: 'Salesforce',
                description: 'Read and update records from a conversation.',
                icon: Building2,
            },
        ],
    },
    {
        label: 'Files & documents',
        blurb: 'Bring your documents in as project knowledge.',
        items: [
            {
                name: 'Google Drive',
                description:
                    'Pull documents from Drive into project knowledge.',
                icon: Cloud,
            },
            {
                name: 'Google Sheets',
                description: 'Read and write spreadsheet data during a chat.',
                icon: Table,
            },
        ],
    },
    {
        label: 'Automation',
        blurb: 'Trigger and receive events across your stack.',
        items: [
            {
                name: 'Webhooks',
                description:
                    'Trigger workflows when a chat or project changes.',
                icon: Webhook,
            },
            {
                name: 'Zapier',
                description: 'Connect AiMe BOT to 6,000+ apps, no code.',
                icon: Zap,
            },
            {
                name: 'n8n',
                description:
                    'Trigger self-hosted n8n workflows from chats and projects.',
                icon: Workflow,
            },
        ],
    },
    {
        label: 'ERP & business systems',
        blurb: 'Connect the systems that run your operations and finance.',
        items: [
            {
                name: 'NetSuite',
                description:
                    'Read and update ERP records — orders, inventory, and finance.',
                icon: Boxes,
            },
        ],
    },
    {
        label: 'Productivity & data',
        blurb: 'Schedules, databases, and code the assistant can use.',
        items: [
            {
                name: 'Calendar',
                description: 'Let the assistant see and schedule events.',
                icon: Calendar,
            },
            {
                name: 'Database',
                description: 'Query your own data sources securely.',
                icon: Database,
            },
            {
                name: 'Code repos',
                description: 'Connect repositories for code-aware answers.',
                icon: Code,
            },
        ],
    },
];
</script>

<template>
    <Head title="Integrations" />

    <div class="w-full p-6">
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight">Integrations</h1>
            <p class="text-sm text-muted-foreground">
                Connect AiMe BOT to the tools you already use, grouped by what
                they do. More coming soon.
            </p>
        </div>

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
                    :key="item.name"
                    class="flex flex-col rounded-xl border bg-card p-5"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                        >
                            <component :is="item.icon" class="size-5" />
                        </div>
                        <span
                            class="rounded-full border border-border bg-muted/60 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                        >
                            Coming soon
                        </span>
                    </div>
                    <p class="font-medium">{{ item.name }}</p>
                    <p class="mt-1 flex-1 text-sm text-muted-foreground">
                        {{ item.description }}
                    </p>
                    <Button variant="outline" size="sm" class="mt-4" disabled>
                        Connect
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
