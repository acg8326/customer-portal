<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChartNoAxesCombined,
    KeyRound,
    Lightbulb,
    MessageSquareHeart,
    MessageSquarePlus,
    ThumbsDown,
    ThumbsUp,
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

type TierSnapshot = {
    enabled: boolean;
    used: number;
    limit: number;
    remaining: number;
    percent: number;
    resets_at: string | null;
};

const props = defineProps<{
    usage: TierSnapshot & {
        period_days: number;
        period: TierSnapshot & { period_days: number };
        session: TierSnapshot & { session_hours: number };
        weekly: TierSnapshot & { weekly_days: number };
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
}>();

const nf = new Intl.NumberFormat();

function compact(n: number): string {
    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(n);
}

// --- Greeting -------------------------------------------------------------------

const page = usePage();

const isSuperAdmin = computed(
    () => page.props.auth?.user?.role === 'super_admin',
);

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

// Short "resets in Xh/Xd" caption — session windows are hours away, weekly
// and period windows are usually days away.
function resetsIn(resetsAt: string | null): string | null {
    if (!resetsAt) {
        return null;
    }

    const diffMs = new Date(resetsAt).getTime() - Date.now();

    if (diffMs <= 0) {
        return null;
    }

    const hours = Math.ceil(diffMs / (1000 * 60 * 60));

    return hours < 48
        ? `resets in ${hours}h`
        : `resets in ${Math.ceil(hours / 24)}d`;
}

function barColorFor(percent: number): string {
    if (percent >= 90) {
        return 'bg-destructive';
    }

    if (percent >= 75) {
        return 'bg-amber-500';
    }

    return 'bg-brand-gold';
}

// The three windows, shortest/most-actionable first.
type TierRow = {
    key: 'session' | 'weekly' | 'period';
    label: string;
    enabled: boolean;
    used: number;
    limit: number;
    percent: number;
    resets_at: string | null;
};

const tierRows = computed<TierRow[]>(() => [
    {
        key: 'session',
        label: 'Session',
        enabled: props.usage.session.enabled,
        used: props.usage.session.used,
        limit: props.usage.session.limit,
        percent: props.usage.session.percent,
        resets_at: props.usage.session.resets_at,
    },
    {
        key: 'weekly',
        label: 'Weekly',
        enabled: props.usage.weekly.enabled,
        used: props.usage.weekly.used,
        limit: props.usage.weekly.limit,
        percent: props.usage.weekly.percent,
        resets_at: props.usage.weekly.resets_at,
    },
    {
        key: 'period',
        label: `${props.usage.period.period_days}-day period`,
        enabled: props.usage.period.enabled,
        used: props.usage.period.used,
        limit: props.usage.period.limit,
        percent: props.usage.period.percent,
        resets_at: props.usage.period.resets_at,
    },
]);

// Named in the warning so the member knows exactly which window is tight.
const nearLimitTiers = computed(() =>
    tierRows.value.filter((t) => t.enabled && t.percent >= 90),
);

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
        <div class="flex items-start justify-between gap-4 px-1 pt-1">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ greeting }}, {{ firstName }}
                </h1>
                <p class="text-sm text-muted-foreground">{{ todayLabel }}</p>
            </div>
            <Link
                v-if="isSuperAdmin"
                href="/analytics"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
                <ChartNoAxesCombined class="size-4" />
                View analytics
            </Link>
        </div>

        <!-- Token usage (the classic full card, shown to everyone) -->
        <section class="rounded-xl border bg-card p-5">
            <div class="mb-4 flex items-center gap-3">
                <div
                    class="flex size-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                >
                    <Zap class="size-5" />
                </div>
                <div>
                    <h2 class="font-semibold tracking-tight">Token usage</h2>
                    <p class="text-xs text-muted-foreground">
                        Session, weekly, and period allowances — each resets on
                        its own schedule
                    </p>
                </div>
            </div>

            <div class="space-y-3">
                <div v-for="tier in tierRows" :key="tier.key">
                    <div class="mb-1 flex items-baseline justify-between">
                        <span class="text-sm font-medium">{{
                            tier.label
                        }}</span>
                        <span class="text-xs text-muted-foreground">
                            <template v-if="tier.enabled">
                                {{ compact(tier.used) }} /
                                {{ compact(tier.limit) }}
                                <template v-if="resetsIn(tier.resets_at)">
                                    · {{ resetsIn(tier.resets_at) }}
                                </template>
                            </template>
                            <template v-else> no limit configured </template>
                        </span>
                    </div>
                    <div
                        v-if="tier.enabled"
                        class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full transition-all"
                            :class="barColorFor(tier.percent)"
                            :style="{
                                width: `${Math.min(100, tier.percent)}%`,
                            }"
                        />
                    </div>
                </div>
            </div>

            <p
                v-if="nearLimitTiers.length > 0"
                class="mt-3 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                You're almost out of tokens for your
                {{
                    nearLimitTiers.map((t) => t.label.toLowerCase()).join(', ')
                }}
                allowance. New messages will be blocked until it resets.
            </p>
        </section>

        <!-- Answer feedback (super admin only) -->
        <section v-if="feedback" class="rounded-xl border bg-card p-4 sm:p-5">
            <div class="mb-4 flex items-center gap-2">
                <MessageSquareHeart class="size-4 text-muted-foreground" />
                <h2 class="font-semibold tracking-tight">Answer feedback</h2>
                <span
                    class="ml-auto flex items-center gap-3 text-sm tabular-nums"
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
                </span>
            </div>

            <p
                v-if="feedback.recent.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                No feedback yet — the thumbs under any AiMe answer land here.
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
