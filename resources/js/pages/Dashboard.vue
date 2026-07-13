<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    FolderOpen,
    MessageSquareHeart,
    MessagesSquare,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
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
