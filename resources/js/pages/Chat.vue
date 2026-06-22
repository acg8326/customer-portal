<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ArrowUp, Sparkles } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { getInitials } from '@/composables/useInitials';
import { chat } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chat',
                href: chat(),
            },
        ],
    },
});

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
};

const props = defineProps<{
    models: { value: string; label: string }[];
    defaultModel: string;
}>();

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name ?? 'there');
const userInitials = computed(() => getInitials(page.props.auth?.user?.name));

// Time-of-day greeting for the empty state.
const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    if (hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
});

const MODEL_STORAGE_KEY = 'chat:model';

function initialModel(): string {
    const saved = localStorage.getItem(MODEL_STORAGE_KEY);

    if (saved && props.models.some((m) => m.value === saved)) {
        return saved;
    }

    return props.defaultModel;
}

const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const scrollRegion = ref<HTMLElement | null>(null);
const model = ref(initialModel());

function onModelChange(value: unknown) {
    if (typeof value !== 'string') {
        return;
    }

    model.value = value;
    localStorage.setItem(MODEL_STORAGE_KEY, value);
}

function readCookie(name: string): string {
    const match = document.cookie.match(
        new RegExp('(^|; )' + name + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[2]) : '';
}

async function scrollToBottom() {
    await nextTick();
    const el = scrollRegion.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

async function send() {
    const text = draft.value.trim();

    if (!text || loading.value) {
        return;
    }

    error.value = null;
    messages.value.push({ role: 'user', content: text });
    draft.value = '';
    loading.value = true;
    await scrollToBottom();

    try {
        const res = await fetch('/chat/message', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
            },
            body: JSON.stringify({
                messages: messages.value,
                model: model.value,
            }),
        });

        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.message ?? 'The assistant could not respond.');
        }

        messages.value.push({ role: 'assistant', content: data.reply });
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    } finally {
        loading.value = false;
        await scrollToBottom();
    }
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
}
</script>

<template>
    <Head title="Chat" />

    <div
        class="mx-auto my-4 flex h-[calc(100svh-6rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border bg-card shadow-sm"
    >
        <!-- Header -->
        <div class="flex items-center justify-between gap-2 border-b px-4 py-3">
            <div class="flex items-center gap-3">
                <div
                    class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-indigo-500 text-white shadow-sm"
                >
                    <Sparkles class="size-5" />
                </div>
                <div class="leading-tight">
                    <p class="text-sm font-semibold">AiMe BOT</p>
                    <p
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span class="size-1.5 rounded-full bg-emerald-500" />
                        Online
                    </p>
                </div>
            </div>

            <Select :model-value="model" @update:model-value="onModelChange">
                <SelectTrigger class="h-8 w-auto min-w-[200px] text-xs">
                    <SelectValue placeholder="Select a model" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="m in models"
                        :key="m.value"
                        :value="m.value"
                        class="text-xs"
                    >
                        {{ m.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Conversation -->
        <div ref="scrollRegion" class="flex-1 space-y-6 overflow-y-auto p-4">
            <!-- Empty state -->
            <div
                v-if="messages.length === 0"
                class="relative flex h-full flex-col items-center justify-center px-4 text-center"
            >
                <!-- Ambient brand glow -->
                <div
                    class="pointer-events-none absolute top-1/2 left-1/2 z-0 size-80 -translate-x-1/2 -translate-y-[60%] rounded-full bg-gradient-to-br from-cyan-400/30 to-indigo-500/30 blur-[100px]"
                />

                <div class="relative z-10 flex flex-col items-center">
                    <div
                        class="mb-5 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-400 to-indigo-500 text-white shadow-lg shadow-indigo-500/30"
                    >
                        <Sparkles class="size-7" />
                    </div>
                    <h2 class="text-2xl font-semibold tracking-tight">
                        {{ greeting }}, {{ userName }}
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Hi, I'm AiMe BOT. Ask me anything.
                    </p>
                </div>
            </div>

            <!-- Messages -->
            <div
                v-for="(m, i) in messages"
                :key="i"
                class="message-row flex items-start gap-3"
                :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
            >
                <!-- Bot avatar -->
                <div
                    v-if="m.role === 'assistant'"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-indigo-500 text-white shadow-sm"
                >
                    <Sparkles class="size-4" />
                </div>

                <div
                    class="max-w-[80%] px-4 py-2.5 text-sm whitespace-pre-wrap"
                    :class="
                        m.role === 'user'
                            ? 'rounded-2xl rounded-tr-sm bg-primary text-primary-foreground'
                            : 'rounded-2xl rounded-tl-sm bg-muted text-foreground'
                    "
                >
                    {{ m.content }}
                </div>

                <!-- User avatar -->
                <div
                    v-if="m.role === 'user'"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary"
                >
                    {{ userInitials }}
                </div>
            </div>

            <!-- Typing indicator -->
            <div v-if="loading" class="flex items-start gap-3">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-indigo-500 text-white shadow-sm"
                >
                    <Sparkles class="size-4" />
                </div>
                <div
                    class="flex items-center gap-2 rounded-2xl rounded-tl-sm bg-muted px-4 py-3 text-sm text-muted-foreground"
                >
                    <Spinner class="size-4" />
                    AiMe is thinking…
                </div>
            </div>
        </div>

        <!-- Error -->
        <div v-if="error" class="px-4">
            <p
                class="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
            >
                {{ error }}
            </p>
        </div>

        <!-- Composer -->
        <div class="p-4">
            <div
                class="flex items-end gap-2 rounded-2xl border border-input bg-background p-2 shadow-sm transition focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
            >
                <textarea
                    v-model="draft"
                    rows="1"
                    placeholder="Message AiMe BOT…"
                    class="max-h-40 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground"
                    @keydown="onKeydown"
                />
                <button
                    type="button"
                    :disabled="loading || draft.trim().length === 0"
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
                    aria-label="Send message"
                    @click="send"
                >
                    <ArrowUp class="size-5" />
                </button>
            </div>
            <p class="mt-2 text-center text-xs text-muted-foreground">
                Enter to send · Shift+Enter for a new line
            </p>
        </div>
    </div>
</template>

<style scoped>
.message-row {
    animation: message-in 0.25s ease-out both;
}

@keyframes message-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .message-row {
        animation: none;
    }
}
</style>
