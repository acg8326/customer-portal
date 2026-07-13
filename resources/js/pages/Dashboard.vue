<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    FolderOpen,
    MessageSquareHeart,
    MessagesSquare,
    Settings2,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    UsersRound,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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
    stats: {
        conversations: number;
        projects: number;
        skills: number;
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
        }[];
        total: number;
        limit: number;
        period_days: number;
    } | null;
}>();

const nf = new Intl.NumberFormat();

function compact(n: number): string {
    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(n);
}

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

// --- Team usage (super admin) ---------------------------------------------------

const editingUsage = ref(false);
const limitDraft = ref(String(props.teamUsage?.limit ?? 0));
const periodDraft = ref(String(props.teamUsage?.period_days ?? 30));

function saveUsageSettings() {
    router.patch(
        '/dashboard/usage-settings',
        {
            token_limit: Number(limitDraft.value),
            period_days: Number(periodDraft.value),
        },
        {
            preserveScroll: true,
            onSuccess: () => (editingUsage.value = false),
        },
    );
}

function resetLabel(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

const tiles = computed(() => [
    {
        label: 'Conversations',
        value: props.stats.conversations,
        icon: MessagesSquare,
    },
    { label: 'Projects', value: props.stats.projects, icon: FolderOpen },
    { label: 'Skills', value: props.stats.skills, icon: Sparkles },
]);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex w-full flex-col gap-4 p-4">
        <!-- Token usage -->
        <section class="rounded-xl border bg-card p-5">
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

        <!-- Team usage + limit settings (super admin only) -->
        <section v-if="teamUsage" class="rounded-xl border bg-card p-5">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    >
                        <UsersRound class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">Team usage</h2>
                        <p class="text-xs text-muted-foreground">
                            Tokens per member in their current
                            {{ teamUsage.period_days }}-day window ·
                            {{
                                teamUsage.limit > 0
                                    ? `limit ${compact(teamUsage.limit)} each`
                                    : 'no limit set'
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ compact(teamUsage.total) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            total, all members
                        </p>
                    </div>
                    <button
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
            </div>

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
                <button
                    type="submit"
                    class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                >
                    Save for everyone
                </button>
                <p class="basis-full text-xs text-muted-foreground">
                    Applies to all members immediately. Changes here override
                    the server's <code>.env</code> defaults.
                </p>
            </form>

            <ul class="divide-y">
                <li
                    v-for="u in teamUsage.users"
                    :key="u.id"
                    class="flex items-center gap-3 py-2"
                >
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
                            v-if="teamUsage.limit > 0"
                            class="h-full rounded-full"
                            :class="
                                u.percent >= 90
                                    ? 'bg-destructive'
                                    : u.percent >= 75
                                      ? 'bg-amber-500'
                                      : 'bg-brand-gold'
                            "
                            :style="{ width: `${Math.min(100, u.percent)}%` }"
                        />
                    </div>
                    <span
                        class="w-24 text-right text-sm text-muted-foreground tabular-nums"
                    >
                        {{ compact(u.used) }}
                    </span>
                    <span
                        class="hidden w-24 text-right text-xs text-muted-foreground sm:block"
                    >
                        resets {{ resetLabel(u.resets_at) }}
                    </span>
                </li>
            </ul>
        </section>

        <!-- Answer feedback (super admin only) -->
        <section v-if="feedback" class="rounded-xl border bg-card p-5">
            <div class="mb-1 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    >
                        <MessageSquareHeart class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">
                            Answer feedback
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Thumbs left on AiMe's replies across the team
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        <ThumbsUp class="size-3.5" />
                        {{ nf.format(feedback.up) }}
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-destructive/10 px-3 py-1 text-sm font-medium text-destructive tabular-nums"
                    >
                        <ThumbsDown class="size-3.5" />
                        {{ nf.format(feedback.down) }}
                    </span>
                </div>
            </div>

            <p
                v-if="feedback.recent.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                No feedback yet — the thumbs under any AiMe answer land here.
            </p>

            <ul v-else class="mt-3 divide-y">
                <li
                    v-for="item in feedback.recent"
                    :key="item.id"
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
                            :is="item.rating === 'up' ? ThumbsUp : ThumbsDown"
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
        </section>

        <!-- Stat tiles -->
        <section class="grid gap-4 sm:grid-cols-3">
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="flex items-center gap-4 rounded-xl border bg-card p-5"
            >
                <div
                    class="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <component :is="tile.icon" class="size-5" />
                </div>
                <div>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ nf.format(tile.value) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ tile.label }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
