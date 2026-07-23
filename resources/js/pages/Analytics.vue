<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleDollarSign,
    Gauge,
    ScrollText,
    Search,
    Settings2,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Analytics', href: '/analytics' }],
        fullWidth: true,
    },
});

const props = defineProps<{
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
            session_token_limit: number | null;
            session_used: number;
            session_percent: number;
            session_resets_at: string | null;
            effective_session_limit: number;
            weekly_token_limit: number | null;
            weekly_used: number;
            weekly_percent: number;
            weekly_resets_at: string | null;
            effective_weekly_limit: number;
        }[];
        total: number;
        limit: number;
        period_days: number;
        session_limit: number;
        session_hours: number;
        weekly_limit: number;
        weekly_days: number;
        models: { value: string; label: string }[];
        default_model: string | null;
        env_default_model: string;
    };
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
    };
    // Gateway traffic only — the in-app chat's SDK doesn't expose response
    // headers. Null until the first gateway request (or after the cache TTL
    // expires with no traffic).
    rateLimits: {
        dimensions: Record<
            string,
            { limit?: string; remaining?: string; reset?: string }
        >;
        captured_at: string;
    } | null;
    logs: {
        data: {
            id: number;
            user: string | null;
            surface: string;
            model: string | null;
            input_tokens: number | null;
            output_tokens: number | null;
            status: number;
            latency_ms: number | null;
            when: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
}>();

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

// --- Tabs -------------------------------------------------------------------------

type InsightTab = 'team' | 'cost' | 'rate-limits' | 'logs';

const tabs: { value: InsightTab; label: string; Icon: Component }[] = [
    { value: 'team', label: 'Usage', Icon: UsersRound },
    { value: 'cost', label: 'Cost & caching', Icon: CircleDollarSign },
    { value: 'rate-limits', label: 'Rate limits', Icon: Gauge },
    { value: 'logs', label: 'Logs', Icon: ScrollText },
];

const insightTab = ref<InsightTab>('team');

// --- Team usage (workspace-wide settings) ------------------------------------------

const editingUsage = ref(false);
const limitDraft = ref(String(props.teamUsage.limit));
const periodDraft = ref(String(props.teamUsage.period_days));
const sessionLimitDraft = ref(String(props.teamUsage.session_limit));
const sessionHoursDraft = ref(String(props.teamUsage.session_hours));
const weeklyLimitDraft = ref(String(props.teamUsage.weekly_limit));
const weeklyDaysDraft = ref(String(props.teamUsage.weekly_days));
const modelDraft = ref(props.teamUsage.default_model ?? 'default');

function saveUsageSettings() {
    router.patch(
        '/analytics/usage-settings',
        {
            token_limit: Number(limitDraft.value),
            period_days: Number(periodDraft.value),
            session_token_limit: Number(sessionLimitDraft.value),
            session_hours: Number(sessionHoursDraft.value),
            weekly_token_limit: Number(weeklyLimitDraft.value),
            weekly_days: Number(weeklyDaysDraft.value),
            default_model: modelDraft.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (editingUsage.value = false),
        },
    );
}

// --- Per-user model + limit ---------------------------------------------------------

type TeamUser = (typeof props.teamUsage)['users'][number];

// Filter the team-usage list by name or role.
const teamSearch = ref('');

const filteredTeamUsers = computed<TeamUser[]>(() => {
    const all = props.teamUsage.users;
    const q = teamSearch.value.trim().toLowerCase();

    if (!q) {
        return all;
    }

    return all.filter(
        (u) =>
            u.name.toLowerCase().includes(q) ||
            u.role.toLowerCase().includes(q),
    );
});

// Which user row is expanded for editing (null = none).
const editingUserId = ref<number | null>(null);
// Drafts for the row being edited: model ('default' = inherit) and limits
// ('' = inherit workspace limit). A type="number" input can write a number
// here; '' means inherit.
const userModelDraft = ref('default');
const userLimitDraft = ref<string | number>('');
const userSessionLimitDraft = ref<string | number>('');
const userWeeklyLimitDraft = ref<string | number>('');
const savingUser = ref(false);

const modelLabel = (value: string | null): string =>
    props.teamUsage.models.find((m) => m.value === value)?.label ?? value ?? '';

function toggleUserEditor(u: TeamUser) {
    if (editingUserId.value === u.id) {
        editingUserId.value = null;

        return;
    }

    editingUserId.value = u.id;
    userModelDraft.value = u.assigned_model ?? 'default';
    userLimitDraft.value = u.token_limit === null ? '' : String(u.token_limit);
    userSessionLimitDraft.value =
        u.session_token_limit === null ? '' : String(u.session_token_limit);
    userWeeklyLimitDraft.value =
        u.weekly_token_limit === null ? '' : String(u.weekly_token_limit);
}

function saveUserLimits(u: TeamUser) {
    if (savingUser.value) {
        return;
    }

    savingUser.value = true;

    // A type="number" input makes Vue hand us a number (or '' when blank), so
    // normalise to a string before trimming.
    const num = (draft: string | number) => {
        const trimmed = String(draft ?? '').trim();

        return trimmed === '' ? null : Number(trimmed);
    };

    router.patch(
        `/analytics/users/${u.id}/limits`,
        {
            assigned_model: userModelDraft.value,
            token_limit: num(userLimitDraft.value),
            session_token_limit: num(userSessionLimitDraft.value),
            weekly_token_limit: num(userWeeklyLimitDraft.value),
        },
        {
            preserveScroll: true,
            onSuccess: () => (editingUserId.value = null),
            onFinish: () => (savingUser.value = false),
        },
    );
}

// --- Logs (filter + paginate) -------------------------------------------------------

const logFilters = ref({
    log_user: '',
    log_surface: '',
    log_status: '',
    log_from: '',
    log_to: '',
});

function applyLogFilters() {
    const params: Record<string, string> = {};

    for (const [key, value] of Object.entries(logFilters.value)) {
        if (value) {
            params[key] = value;
        }
    }

    // Only refetch `logs` — the rest of the page (usage, cost, rate limits)
    // doesn't need to reload just because the log filters changed.
    router.get('/analytics', params, {
        preserveState: true,
        preserveScroll: true,
        only: ['logs'],
    });
}

function statusClass(status: number): string {
    if (status >= 500) {
        return 'text-destructive';
    }

    if (status >= 400) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-emerald-600 dark:text-emerald-400';
}
</script>

<template>
    <Head title="Analytics" />

    <div class="flex w-full flex-col gap-4 p-4">
        <div class="px-1 pt-1">
            <h1 class="text-xl font-semibold tracking-tight">Analytics</h1>
            <p class="text-sm text-muted-foreground">
                Org-wide usage, governance, and estimated API cost — visible
                only to you, since Anthropic's own console can't tell one
                developer's traffic from another's on a shared key.
            </p>
        </div>

        <section class="rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-5"
            >
                <div class="inline-flex gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="tab in tabs"
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

            <!-- Usage tab -->
            <div v-if="insightTab === 'team'" class="p-4 sm:p-5">
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
                            for="usage-session-limit"
                        >
                            Session limit (0 = unlimited)
                        </label>
                        <input
                            id="usage-session-limit"
                            v-model="sessionLimitDraft"
                            type="number"
                            min="0"
                            step="10000"
                            class="h-9 w-40 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="usage-session-hours"
                        >
                            Session (hours)
                        </label>
                        <input
                            id="usage-session-hours"
                            v-model="sessionHoursDraft"
                            type="number"
                            min="1"
                            max="744"
                            class="h-9 w-24 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="usage-weekly-limit"
                        >
                            Weekly limit (0 = unlimited)
                        </label>
                        <input
                            id="usage-weekly-limit"
                            v-model="weeklyLimitDraft"
                            type="number"
                            min="0"
                            step="50000"
                            class="h-9 w-40 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="usage-weekly-days"
                        >
                            Weekly (days)
                        </label>
                        <input
                            id="usage-weekly-days"
                            v-model="weeklyDaysDraft"
                            type="number"
                            min="1"
                            max="365"
                            class="h-9 w-24 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
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

                <div
                    v-if="teamUsage.users.length > 1"
                    class="relative mb-3 max-w-xs"
                >
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="teamSearch"
                        type="search"
                        placeholder="Filter members"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                </div>

                <ul class="divide-y">
                    <li v-for="u in filteredTeamUsers" :key="u.id" class="py-2">
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
                            <!-- Session / Weekly / Period — three thin
                                 segments so it's obvious which window is
                                 tightest for this member at a glance. -->
                            <div class="flex flex-1 items-center gap-1.5">
                                <div
                                    v-for="seg in [
                                        {
                                            label: 'S',
                                            limit: u.effective_session_limit,
                                            percent: u.session_percent,
                                        },
                                        {
                                            label: 'W',
                                            limit: u.effective_weekly_limit,
                                            percent: u.weekly_percent,
                                        },
                                        {
                                            label: 'P',
                                            limit: u.effective_limit,
                                            percent: u.percent,
                                        },
                                    ]"
                                    :key="seg.label"
                                    class="flex flex-1 items-center gap-1"
                                    :title="`${seg.label === 'S' ? 'Session' : seg.label === 'W' ? 'Weekly' : 'Period'}: ${seg.percent}% used`"
                                >
                                    <span
                                        class="text-[0.65rem] font-medium text-muted-foreground/70"
                                        >{{ seg.label }}</span
                                    >
                                    <div
                                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            v-if="seg.limit > 0"
                                            class="h-full rounded-full"
                                            :class="
                                                seg.percent >= 90
                                                    ? 'bg-destructive'
                                                    : seg.percent >= 75
                                                      ? 'bg-amber-500'
                                                      : 'bg-brand-gold'
                                            "
                                            :style="{
                                                width: `${Math.min(100, seg.percent)}%`,
                                            }"
                                        />
                                    </div>
                                </div>
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
                                (u.assigned_model ||
                                    u.token_limit !== null ||
                                    u.session_token_limit !== null ||
                                    u.weekly_token_limit !== null)
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
                                v-if="u.session_token_limit !== null"
                                class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                session:
                                {{
                                    u.session_token_limit === 0
                                        ? 'unlimited'
                                        : compact(u.session_token_limit)
                                }}
                            </span>
                            <span
                                v-if="u.weekly_token_limit !== null"
                                class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                weekly:
                                {{
                                    u.weekly_token_limit === 0
                                        ? 'unlimited'
                                        : compact(u.weekly_token_limit)
                                }}
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
                                    :for="`user-session-limit-${u.id}`"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                >
                                    Session limit
                                </label>
                                <input
                                    :id="`user-session-limit-${u.id}`"
                                    v-model="userSessionLimitDraft"
                                    type="number"
                                    min="0"
                                    step="10000"
                                    placeholder="Inherit workspace"
                                    class="h-9 w-36 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                                />
                            </div>
                            <div>
                                <label
                                    :for="`user-weekly-limit-${u.id}`"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                >
                                    Weekly limit
                                </label>
                                <input
                                    :id="`user-weekly-limit-${u.id}`"
                                    v-model="userWeeklyLimitDraft"
                                    type="number"
                                    min="0"
                                    step="50000"
                                    placeholder="Inherit workspace"
                                    class="h-9 w-36 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                                />
                            </div>
                            <div>
                                <label
                                    :for="`user-limit-${u.id}`"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                >
                                    Period limit
                                </label>
                                <input
                                    :id="`user-limit-${u.id}`"
                                    v-model="userLimitDraft"
                                    type="number"
                                    min="0"
                                    step="50000"
                                    placeholder="Inherit workspace"
                                    class="h-9 w-36 rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
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
                                Leave a limit blank to inherit the workspace
                                setting for that window; 0 = unlimited for
                                {{ u.name }}. A locked model overrides their
                                picker.
                            </p>
                        </form>
                    </li>
                    <li
                        v-if="filteredTeamUsers.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No members match "{{ teamSearch.trim() }}".
                    </li>
                </ul>
            </div>

            <!-- Cost & caching tab -->
            <div v-if="insightTab === 'cost'" class="p-4 sm:p-5">
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

            <!-- Rate limits tab -->
            <div v-if="insightTab === 'rate-limits'" class="p-4 sm:p-5">
                <p class="mb-3 text-xs text-muted-foreground">
                    Anthropic's own rate-limit gauges for the shared API key —
                    <span class="font-medium text-foreground"
                        >gateway (Claude Code / API) traffic only</span
                    >; the in-app chat's SDK doesn't expose these headers.
                </p>

                <div
                    v-if="rateLimits"
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="(dim, name) in rateLimits.dimensions"
                        :key="name"
                        class="rounded-lg border bg-muted/30 p-3"
                    >
                        <p
                            class="text-xs font-medium text-muted-foreground capitalize"
                        >
                            {{ String(name).replace(/-/g, ' ') }}
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ dim.remaining ?? '—' }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                                >/ {{ dim.limit ?? '—' }}</span
                            >
                        </p>
                        <p
                            v-if="dim.reset"
                            class="text-xs text-muted-foreground"
                        >
                            resets {{ dim.reset }}
                        </p>
                    </div>
                </div>
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No rate-limit data yet — it appears after the first gateway
                    request, and goes stale again after a few minutes of no
                    gateway traffic.
                </p>

                <p v-if="rateLimits" class="mt-3 text-xs text-muted-foreground">
                    Captured {{ rateLimits.captured_at }}.
                </p>
            </div>

            <!-- Logs tab -->
            <div v-if="insightTab === 'logs'" class="p-4 sm:p-5">
                <form
                    class="mb-4 flex flex-wrap items-end gap-3"
                    @submit.prevent="applyLogFilters"
                >
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="log-surface"
                        >
                            Surface
                        </label>
                        <select
                            id="log-surface"
                            v-model="logFilters.log_surface"
                            class="h-9 w-32 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        >
                            <option value="">All</option>
                            <option value="chat">Chat</option>
                            <option value="gateway">Gateway</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="log-status"
                        >
                            Status
                        </label>
                        <select
                            id="log-status"
                            v-model="logFilters.log_status"
                            class="h-9 w-28 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        >
                            <option value="">All</option>
                            <option value="2xx">2xx</option>
                            <option value="4xx">4xx</option>
                            <option value="5xx">5xx</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="log-user"
                        >
                            Member
                        </label>
                        <select
                            id="log-user"
                            v-model="logFilters.log_user"
                            class="h-9 w-48 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        >
                            <option value="">All members</option>
                            <option
                                v-for="u in teamUsage.users"
                                :key="u.id"
                                :value="String(u.id)"
                            >
                                {{ u.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="log-from"
                        >
                            From
                        </label>
                        <input
                            id="log-from"
                            v-model="logFilters.log_from"
                            type="date"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            for="log-to"
                        >
                            To
                        </label>
                        <input
                            id="log-to"
                            v-model="logFilters.log_to"
                            type="date"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        />
                    </div>
                    <button
                        type="submit"
                        class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        Filter
                    </button>
                </form>

                <div v-if="logs.data.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-3 font-medium">When</th>
                                <th class="py-2 pr-3 font-medium">User</th>
                                <th class="py-2 pr-3 font-medium">Surface</th>
                                <th class="py-2 pr-3 font-medium">Model</th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Input
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Output
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Status
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Latency
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in logs.data" :key="row.id">
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ row.when ?? '—' }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ row.user ?? '—' }}
                                </td>
                                <td
                                    class="py-2 pr-3 text-muted-foreground capitalize"
                                >
                                    {{ row.surface }}
                                </td>
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ row.model ?? '—' }}
                                </td>
                                <td
                                    class="py-2 pr-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ row.input_tokens ?? '—' }}
                                </td>
                                <td
                                    class="py-2 pr-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ row.output_tokens ?? '—' }}
                                </td>
                                <td
                                    class="py-2 pr-3 text-right font-medium tabular-nums"
                                    :class="statusClass(row.status)"
                                >
                                    {{ row.status }}
                                </td>
                                <td
                                    class="py-2 text-right text-muted-foreground tabular-nums"
                                >
                                    {{
                                        row.latency_ms !== null
                                            ? `${row.latency_ms}ms`
                                            : '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No requests match these filters.
                </p>

                <div
                    v-if="logs.last_page > 1"
                    class="mt-4 flex flex-wrap gap-1"
                >
                    <Link
                        v-for="(link, i) in logs.links"
                        :key="i"
                        :href="link.url ?? ''"
                        preserve-scroll
                        class="rounded-md border px-3 py-1.5 text-sm"
                        :class="[
                            link.active
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                    >
                        <span v-html="link.label" />
                    </Link>
                </div>
            </div>
        </section>
    </div>
</template>
