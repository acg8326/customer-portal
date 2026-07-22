<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    CircleDollarSign,
    KeyRound,
    Lightbulb,
    MessageSquareHeart,
    MessageSquarePlus,
    Settings2,
    ThumbsDown,
    ThumbsUp,
    UsersRound,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
        fullWidth: true,
    },
});

const props = defineProps<{
    usage: {
        enabled: boolean;
        used: number;
        limit: number;
        remaining: number;
        percent: number;
        resets_at: string | null;
        period_days: number;
    };
    // Super admin only — null hides the card entirely.
    feedback: {
        up: number;
        down: number;
        recent: {
            id: number;
            rating: 'up' | 'down';
            excerpt: string;
            conversation_id: number;
            conversation: string | null;
            user: string | null;
            when: string | null;
        }[];
        entries: {
            id: number;
            type: 'feedback' | 'suggestion' | 'api_request';
            message: string;
            user: string | null;
            when: string | null;
        }[];
    } | null;
    // Super admin only — null hides the card entirely.
    teamUsage: {
        users: {
            id: number;
            name: string;
            role: string;
            used: number;
            percent: number;
            resets_at: string | null;
            assigned_model: string | null;
            token_limit: number | null;
            effective_limit: number;
        }[];
        total: number;
        limit: number;
        period_days: number;
        models: { value: string; label: string }[];
        default_model: string | null;
        env_default_model: string;
    } | null;
    // Super admin only — null hides the card entirely.
    costEfficiency: {
        models: {
            model: string;
            label: string;
            provider: string;
            input_tokens: number;
            output_tokens: number;
            cost: number;
        }[];
        total_usd: number;
        cache: {
            hit_rate: number | null;
            read_tokens: number;
            write_tokens: number;
            uncached_tokens: number;
            saved_usd: number;
        };
    } | null;
}>();

const nf = new Intl.NumberFormat();

function compact(n: number): string {
    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(n);
}

function usd(n: number): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(n);
}

// --- Greeting -------------------------------------------------------------------

const page = usePage();

const firstName = computed(() => {
    const name = (page.props.auth?.user?.name ?? '').trim();

    return name.split(' ')[0] || 'there';
});

const greeting = computed(() => {
    const h = new Date().getHours();

    if (h < 12) {
        return 'Good morning';
    }

    if (h < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
});

const todayLabel = new Date().toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
});

const resetsLabel = computed(() => {
    if (!props.usage.resets_at) {
        return null;
    }

    const d = new Date(props.usage.resets_at);

    return d.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
});

const barColor = computed(() => {
    if (props.usage.percent >= 90) {
        return 'bg-destructive';
    }

    if (props.usage.percent >= 75) {
        return 'bg-amber-500';
    }

    return 'bg-brand-gold';
});

// --- Insights tabs (super admin) --------------------------------------------------

type InsightTab = 'team' | 'cost' | 'feedback';

const insightTabs = computed(() => {
    const tabs: { value: InsightTab; label: string; Icon: Component }[] = [];

    if (props.teamUsage) {
        tabs.push({ value: 'team', label: 'Team usage', Icon: UsersRound });
    }

    if (props.costEfficiency) {
        tabs.push({
            value: 'cost',
            label: 'Cost & efficiency',
            Icon: CircleDollarSign,
        });
    }

    if (props.feedback) {
        tabs.push({
            value: 'feedback',
            label: 'Feedback',
            Icon: MessageSquareHeart,
        });
    }

    return tabs;
});

const insightTab = ref<InsightTab>(
    props.teamUsage ? 'team' : props.costEfficiency ? 'cost' : 'feedback',
);

// --- Team usage (super admin) ---------------------------------------------------

const editingUsage = ref(false);
const limitDraft = ref(String(props.teamUsage?.limit ?? 0));
const periodDraft = ref(String(props.teamUsage?.period_days ?? 30));
const modelDraft = ref(props.teamUsage?.default_model ?? 'default');

function saveUsageSettings() {
    router.patch(
        '/dashboard/usage-settings',
        {
            token_limit: Number(limitDraft.value),
            period_days: Number(periodDraft.value),
            default_model: modelDraft.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (editingUsage.value = false),
        },
    );
}

// --- Per-user model + limit (super admin) ---------------------------------------

type TeamUser = NonNullable<typeof props.teamUsage>['users'][number];

// Which user row is expanded for editing (null = none).
const editingUserId = ref<number | null>(null);
// Drafts for the row being edited: model ('default' = inherit) and limit
// ('' = inherit workspace limit).
const userModelDraft = ref('default');
const userLimitDraft = ref('');
const savingUser = ref(false);

const modelLabel = (value: string | null): string =>
    props.teamUsage?.models.find((m) => m.value === value)?.label ??
    value ??
    '';

function toggleUserEditor(u: TeamUser) {
    if (editingUserId.value === u.id) {
        editingUserId.value = null;

        return;
    }

    editingUserId.value = u.id;
    userModelDraft.value = u.assigned_model ?? 'default';
    userLimitDraft.value = u.token_limit === null ? '' : String(u.token_limit);
}

function saveUserLimits(u: TeamUser) {
    if (savingUser.value) {
        return;
    }

    savingUser.value = true;

    const trimmed = userLimitDraft.value.trim();

    router.patch(
        `/dashboard/users/${u.id}/limits`,
        {
            assigned_model: userModelDraft.value,
            token_limit: trimmed === '' ? null : Number(trimmed),
        },
        {
            preserveScroll: true,
            onSuccess: () => (editingUserId.value = null),
            onFinish: () => (savingUser.value = false),
        },
    );
}

// --- Feedback & suggestions (everyone) --------------------------------------------

const fbType = ref<'feedback' | 'suggestion'>('feedback');
const fbMessage = ref('');
const fbSending = ref(false);

function sendFeedback() {
    if (!fbMessage.value.trim() || fbSending.value) {
        return;
    }

    fbSending.value = true;

    router.post(
        '/feedback',
        { type: fbType.value, message: fbMessage.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => (fbMessage.value = ''),
            onFinish: () => (fbSending.value = false),
        },
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex w-full flex-col gap-4 p-4">
        <!-- Greeting -->
        <div class="px-1 pt-1">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ greeting }}, {{ firstName }}
            </h1>
            <p class="text-sm text-muted-foreground">{{ todayLabel }}</p>
        </div>

        <!-- KPI strip (super admin) — personal usage lives here as a tile -->
        <template v-if="teamUsage">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <!-- Your tokens -->
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-medium text-muted-foreground">
                            Your tokens
                        </p>
                        <Zap class="size-4 text-brand-gold" />
                    </div>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">
                        {{ compact(usage.used) }}
                        <span
                            v-if="usage.enabled"
                            class="text-sm font-normal text-muted-foreground"
                        >
                            / {{ compact(usage.limit) }}
                        </span>
                    </p>
                    <template v-if="usage.enabled">
                        <div
                            class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="barColor"
                                :style="{
                                    width: `${Math.min(100, usage.percent)}%`,
                                }"
                            />
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            {{ usage.percent }}% used
                            <template v-if="resetsLabel">
                                · resets {{ resetsLabel }}
                            </template>
                        </p>
                    </template>
                    <p v-else class="mt-1.5 text-xs text-muted-foreground">
                        no limit configured
                    </p>
                </div>

                <!-- Team total -->
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-medium text-muted-foreground">
                            Team tokens
                        </p>
                        <UsersRound class="size-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">
                        {{ compact(teamUsage.total) }}
                    </p>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        {{ teamUsage.users.length }} members ·
                        {{
                            teamUsage.limit > 0
                                ? `limit ${compact(teamUsage.limit)} each`
                                : 'no limit set'
                        }}
                    </p>
                </div>

                <!-- Est. spend -->
                <div
                    v-if="costEfficiency"
                    class="rounded-xl border bg-card p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-medium text-muted-foreground">
                            Est. API spend
                        </p>
                        <CircleDollarSign
                            class="size-4 text-muted-foreground"
                        />
                    </div>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">
                        {{ usd(costEfficiency.total_usd) }}
                    </p>
                    <p
                        class="mt-1.5 text-xs text-emerald-600 dark:text-emerald-400"
                    >
                        {{ usd(costEfficiency.cache.saved_usd) }} saved by
                        caching
                    </p>
                </div>

                <!-- Feedback score -->
                <div v-if="feedback" class="rounded-xl border bg-card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-medium text-muted-foreground">
                            Answer feedback
                        </p>
                        <MessageSquareHeart
                            class="size-4 text-muted-foreground"
                        />
                    </div>
                    <p
                        class="mt-2 flex items-center gap-3 text-2xl font-semibold tabular-nums"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400"
                        >
                            <ThumbsUp class="size-4" />
                            {{ nf.format(feedback.up) }}
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 text-destructive"
                        >
                            <ThumbsDown class="size-4" />
                            {{ nf.format(feedback.down) }}
                        </span>
                    </p>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        thumbs on AiMe's replies
                    </p>
                </div>
            </div>

            <p
                v-if="usage.enabled && usage.percent >= 90"
                class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                You're almost out of tokens for this period. New messages will
                be blocked until your allowance resets.
            </p>
        </template>

        <!-- Token usage (members & admins — the classic full card) -->
        <section v-else class="rounded-xl border bg-card p-5">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                    >
                        <Zap class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">
                            Token usage
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="usage.enabled">
                                Your allowance for this
                                {{ usage.period_days }}-day period
                                <template v-if="resetsLabel">
                                    · resets {{ resetsLabel }}
                                </template>
                            </template>
                            <template v-else>
                                Usage tracking (no limit configured)
                            </template>
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ compact(usage.used) }}
                        <span
                            v-if="usage.enabled"
                            class="text-sm font-normal text-muted-foreground"
                        >
                            / {{ compact(usage.limit) }}
                        </span>
                    </p>
                    <p class="text-xs text-muted-foreground">tokens used</p>
                </div>
            </div>

            <template v-if="usage.enabled">
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="barColor"
                        :style="{ width: `${Math.min(100, usage.percent)}%` }"
                    />
                </div>
                <div
                    class="mt-2 flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ usage.percent }}% used</span>
                    <span
                        >{{ nf.format(usage.remaining) }} tokens remaining</span
                    >
                </div>
                <p
                    v-if="usage.percent >= 90"
                    class="mt-3 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                >
                    You're almost out of tokens for this period. New messages
                    will be blocked until your allowance resets.
                </p>
            </template>
        </section>

        <!-- Organization insights (super admin only) — one card, tabbed -->
        <section v-if="insightTabs.length" class="rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-5"
            >
                <div class="inline-flex gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="tab in insightTabs"
                        :key="tab.value"
                        type="button"
                        class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition-colors"
                        :class="
                            insightTab === tab.value
                                ? 'bg-card font-medium shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="insightTab = tab.value"
                    >
                        <component :is="tab.Icon" class="size-4" />
                        {{ tab.label }}
                    </button>
                </div>

                <!-- Contextual header meta per tab -->
                <button
                    v-if="insightTab === 'team'"
                    type="button"
                    class="rounded-md border p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    :title="
                        editingUsage
                            ? 'Close settings'
                            : 'Set the per-user token limit and period'
                    "
                    @click="editingUsage = !editingUsage"
                >
                    <Settings2 class="size-4" />
                </button>
            </div>

            <!-- Team usage tab -->
            <div v-if="insightTab === 'team' && teamUsage" class="p-4 sm:p-5">
                <p class="mb-3 text-xs text-muted-foreground">
                    Tokens per member in their current
                    {{ teamUsage.period_days }}-day window ·
                    {{
                        teamUsage.limit > 0
                            ? `limit ${compact(teamUsage.limit)} each`
                            : 'no limit set'
                    }}
                </p>

                <form
                    v-if="editingUsage"
                    class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border bg-muted/30 p-3"
                    @submit.prevent="saveUsageSettings"
                >
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="usage-limit"
                        >
                            Token limit per user (0 = unlimited)
                        </label>
                        <input
                            id="usage-limit"
                            v-model="limitDraft"
                            type="number"
                            min="0"
                            step="50000"
                            class="h-9 w-44 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="usage-period"
                        >
                            Period (days)
                        </label>
                        <input
                            id="usage-period"
                            v-model="periodDraft"
                            type="number"
                            min="1"
                            max="365"
                            class="h-9 w-28 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="usage-model"
                        >
                            Default model (new chats)
                        </label>
                        <select
                            id="usage-model"
                            v-model="modelDraft"
                            class="h-9 w-64 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        >
                            <option value="default">
                                Server default —
                                {{ teamUsage.env_default_model }}
                            </option>
                            <option
                                v-for="m in teamUsage.models"
                                :key="m.value"
                                :value="m.value"
                            >
                                {{ m.label }}
                            </option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        Save for everyone
                    </button>
                    <p class="basis-full text-xs text-muted-foreground">
                        Applies to all members immediately. Changes here
                        override the server's <code>.env</code> defaults.
                    </p>
                </form>

                <ul class="divide-y">
                    <li v-for="u in teamUsage.users" :key="u.id" class="py-2">
                        <div class="flex items-center gap-3">
                            <span class="w-44 truncate text-sm font-medium">
                                {{ u.name }}
                                <span
                                    v-if="u.role !== 'user'"
                                    class="ml-1 text-xs font-normal text-brand-gold"
                                    >{{
                                        u.role === 'super_admin'
                                            ? 'super admin'
                                            : 'admin'
                                    }}</span
                                >
                            </span>
                            <div
                                class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    v-if="u.effective_limit > 0"
                                    class="h-full rounded-full"
                                    :class="
                                        u.percent >= 90
                                            ? 'bg-destructive'
                                            : u.percent >= 75
                                              ? 'bg-amber-500'
                                              : 'bg-brand-gold'
                                    "
                                    :style="{
                                        width: `${Math.min(100, u.percent)}%`,
                                    }"
                                />
                            </div>
                            <span
                                class="w-24 text-right text-sm text-muted-foreground tabular-nums"
                            >
                                {{ compact(u.used) }}
                            </span>
                            <button
                                type="button"
                                class="rounded-md border p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                :title="`Set model & token limit for ${u.name}`"
                                @click="toggleUserEditor(u)"
                            >
                                <Settings2 class="size-3.5" />
                            </button>
                        </div>

                        <!-- Per-user model + limit badges (when overridden) -->
                        <div
                            v-if="
                                editingUserId !== u.id &&
                                (u.assigned_model || u.token_limit !== null)
                            "
                            class="mt-1 ml-44 flex flex-wrap gap-1.5"
                        >
                            <span
                                v-if="u.assigned_model"
                                class="inline-flex items-center rounded-full bg-brand-navy/5 px-2 py-0.5 text-xs text-brand-navy dark:bg-brand-gold/10 dark:text-brand-gold"
                            >
                                {{ modelLabel(u.assigned_model) }}
                            </span>
                            <span
                                v-if="u.token_limit !== null"
                                class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                {{
                                    u.token_limit === 0
                                        ? 'unlimited'
                                        : `${compact(u.token_limit)} cap`
                                }}
                            </span>
                        </div>

                        <!-- Inline editor -->
                        <form
                            v-if="editingUserId === u.id"
                            class="mt-2 flex flex-wrap items-end gap-3 rounded-lg border bg-muted/30 p-3"
                            @submit.prevent="saveUserLimits(u)"
                        >
                            <div>
                                <label
                                    :for="`user-model-${u.id}`"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                >
                                    Model
                                </label>
                                <select
                                    :id="`user-model-${u.id}`"
                                    v-model="userModelDraft"
                                    class="h-9 w-56 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                                >
                                    <option value="default">
                                        Free choice (workspace default)
                                    </option>
                                    <option
                                        v-for="m in teamUsage.models"
                                        :key="m.value"
                                        :value="m.value"
                                    >
                                        Locked to {{ m.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label
                                    :for="`user-limit-${u.id}`"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                >
                                    Token limit
                                </label>
                                <input
                                    :id="`user-limit-${u.id}`"
                                    v-model="userLimitDraft"
                                    type="number"
                                    min="0"
                                    step="50000"
                                    placeholder="Inherit workspace"
                                    class="h-9 w-44 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                                />
                            </div>
                            <button
                                type="submit"
                                :disabled="savingUser"
                                class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="h-9 rounded-md px-3 text-sm text-muted-foreground hover:text-foreground"
                                @click="editingUserId = null"
                            >
                                Cancel
                            </button>
                            <p class="basis-full text-xs text-muted-foreground">
                                Leave the limit blank to inherit the workspace
                                limit; 0 = unlimited for {{ u.name }}. A locked
                                model overrides their picker.
                            </p>
                        </form>
                    </li>
                </ul>
            </div>

            <!-- Cost & efficiency tab -->
            <div
                v-if="insightTab === 'cost' && costEfficiency"
                class="p-4 sm:p-5"
            >
                <div class="mb-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border bg-muted/30 p-3">
                        <p class="text-xs text-muted-foreground">
                            Prompt-cache hit rate
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{
                                costEfficiency.cache.hit_rate !== null
                                    ? Math.round(
                                          costEfficiency.cache.hit_rate * 100,
                                      ) + '%'
                                    : '—'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            of Claude input tokens served from cache
                        </p>
                    </div>
                    <div class="rounded-lg border bg-muted/30 p-3">
                        <p class="text-xs text-muted-foreground">
                            Saved by caching
                        </p>
                        <p
                            class="mt-1 text-xl font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ usd(costEfficiency.cache.saved_usd) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ compact(costEfficiency.cache.read_tokens) }}
                            tokens re-read at ~10% price
                        </p>
                    </div>
                    <div class="rounded-lg border bg-muted/30 p-3">
                        <p class="text-xs text-muted-foreground">
                            Uncached input
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ compact(costEfficiency.cache.uncached_tokens) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            + {{ compact(costEfficiency.cache.write_tokens) }}
                            written to cache
                        </p>
                    </div>
                </div>

                <div
                    v-if="costEfficiency.models.length"
                    class="overflow-x-auto"
                >
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-3 font-medium">Model</th>
                                <th class="py-2 pr-3 font-medium">Provider</th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Input
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Output
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Est. cost
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="m in costEfficiency.models"
                                :key="m.model"
                            >
                                <td class="py-2 pr-3 font-medium">
                                    {{ m.label }}
                                </td>
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ m.provider }}
                                </td>
                                <td
                                    class="py-2 pr-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ compact(m.input_tokens) }}
                                </td>
                                <td
                                    class="py-2 pr-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ compact(m.output_tokens) }}
                                </td>
                                <td
                                    class="py-2 text-right font-medium tabular-nums"
                                >
                                    {{ usd(m.cost) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No conversations yet.
                </p>

                <p class="mt-3 text-xs text-muted-foreground">
                    Estimates from per-model prices in config (override with
                    <code>LLM_PRICES</code>). Deleted chats drop out of the
                    totals; cache tracking counts from the day it was deployed.
                </p>
            </div>

            <!-- Feedback tab -->
            <div
                v-if="insightTab === 'feedback' && feedback"
                class="p-4 sm:p-5"
            >
                <p
                    v-if="feedback.recent.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No feedback yet — the thumbs under any AiMe answer land
                    here.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="item in feedback.recent"
                        :key="'thumb-' + item.id"
                        class="flex items-start gap-3 py-2.5"
                    >
                        <span
                            class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full"
                            :class="
                                item.rating === 'up'
                                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : 'bg-destructive/10 text-destructive'
                            "
                        >
                            <component
                                :is="
                                    item.rating === 'up' ? ThumbsUp : ThumbsDown
                                "
                                class="size-3"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">{{ item.excerpt }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <template v-if="item.user"
                                    >{{ item.user }} · </template
                                >{{ item.conversation ?? 'Deleted chat'
                                }}<template v-if="item.when">
                                    · {{ item.when }}</template
                                >
                            </p>
                        </div>
                    </li>
                </ul>

                <!-- Written feedback & suggestions from the dashboard card -->
                <div class="mt-5 border-t pt-4">
                    <p
                        class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Written feedback & suggestions
                    </p>

                    <p
                        v-if="feedback.entries.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        Nothing yet — what members send from their dashboard's
                        "Feedback & suggestions" card lands here.
                    </p>

                    <ul v-else class="divide-y">
                        <li
                            v-for="entry in feedback.entries"
                            :key="'entry-' + entry.id"
                            class="flex items-start gap-3 py-2.5"
                        >
                            <span
                                class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    entry.type === 'feedback'
                                        ? 'bg-muted text-muted-foreground'
                                        : 'bg-brand-gold/10 text-brand-gold'
                                "
                            >
                                <component
                                    :is="
                                        entry.type === 'suggestion'
                                            ? Lightbulb
                                            : entry.type === 'api_request'
                                              ? KeyRound
                                              : MessageSquareHeart
                                    "
                                    class="size-3"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm whitespace-pre-wrap">
                                    {{ entry.message }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    <span class="capitalize">{{
                                        entry.type === 'api_request'
                                            ? 'API request'
                                            : entry.type
                                    }}</span>
                                    <template v-if="entry.user">
                                        · {{ entry.user }}</template
                                    ><template v-if="entry.when">
                                        · {{ entry.when }}</template
                                    >
                                </p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Feedback & suggestions (everyone) -->
        <section class="rounded-xl border bg-card p-5">
            <div class="mb-4 flex items-center gap-3">
                <div
                    class="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <MessageSquarePlus class="size-5" />
                </div>
                <div>
                    <h2 class="font-semibold tracking-tight">
                        Feedback & suggestions
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Tell us what's working, what's broken, or what the
                        portal should do next
                    </p>
                </div>
            </div>

            <form class="flex flex-col gap-3" @submit.prevent="sendFeedback">
                <div class="flex flex-wrap items-start gap-3">
                    <select
                        v-model="fbType"
                        class="h-9 w-36 shrink-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        aria-label="Type"
                    >
                        <option value="feedback">Feedback</option>
                        <option value="suggestion">Suggestion</option>
                    </select>
                    <textarea
                        v-model="fbMessage"
                        rows="2"
                        maxlength="2000"
                        :placeholder="
                            fbType === 'suggestion'
                                ? 'e.g. It would help if AiMe could…'
                                : 'e.g. The export button gives me the wrong…'
                        "
                        class="min-w-0 flex-1 resize-y rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                </div>
                <div>
                    <button
                        type="submit"
                        :disabled="fbSending || !fbMessage.trim()"
                        class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {{ fbSending ? 'Sending…' : 'Send' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
