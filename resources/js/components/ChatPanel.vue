<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowUp, Menu, Sparkles, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import ChatSidebar from '@/components/ChatSidebar.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { getInitials } from '@/composables/useInitials';

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
};

type ConversationSummary = {
    id: number;
    title: string;
};

const props = withDefaults(
    defineProps<{
        models: { value: string; label: string }[];
        defaultModel: string;
        conversations: ConversationSummary[];
        projectId?: number | null;
    }>(),
    { projectId: null },
);

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name ?? 'there');
const userInitials = computed(() => getInitials(page.props.auth?.user?.name));

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

const conversations = ref<ConversationSummary[]>([...props.conversations]);
const activeId = ref<number | null>(null);
const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const scrollRegion = ref<HTMLElement | null>(null);
const model = ref(initialModel());
const sidebarOpen = ref(false);

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

function jsonHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
    };
}

async function scrollToBottom() {
    await nextTick();
    const el = scrollRegion.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

function newChat() {
    activeId.value = null;
    messages.value = [];
    error.value = null;
    sidebarOpen.value = false;
}

async function selectConversation(id: number) {
    sidebarOpen.value = false;

    if (id === activeId.value) {
        return;
    }

    error.value = null;

    try {
        const res = await fetch(`/chat/conversations/${id}`, {
            headers: jsonHeaders(),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error('Could not load that conversation.');
        }

        activeId.value = data.id;
        messages.value = data.messages;

        if (typeof data.model === 'string') {
            model.value = data.model;
        }

        await scrollToBottom();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    }
}

function bumpToTop(id: number, title: string) {
    conversations.value = [
        { id, title },
        ...conversations.value.filter((c) => c.id !== id),
    ];
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
            headers: jsonHeaders(),
            body: JSON.stringify({
                conversation_id: activeId.value,
                project_id: props.projectId,
                content: text,
                model: model.value,
            }),
        });

        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.message ?? 'The assistant could not respond.');
        }

        activeId.value = data.conversation_id;
        messages.value.push({ role: 'assistant', content: data.reply });
        bumpToTop(data.conversation_id, data.title);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    } finally {
        loading.value = false;
        await scrollToBottom();
    }
}

async function removeConversation(id: number) {
    try {
        const res = await fetch(`/chat/conversations/${id}`, {
            method: 'DELETE',
            headers: jsonHeaders(),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error('Could not delete that conversation.');
        }

        conversations.value = data.conversations;

        if (activeId.value === id) {
            newChat();
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
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
    <div
        class="relative flex h-full w-full overflow-hidden rounded-2xl border bg-card shadow-sm"
    >
        <!-- Sidebar (desktop) -->
        <aside class="hidden w-64 shrink-0 border-r md:block">
            <ChatSidebar
                :conversations="conversations"
                :active-id="activeId"
                @new="newChat"
                @select="selectConversation"
                @remove="removeConversation"
            />
        </aside>

        <!-- Sidebar (mobile drawer) -->
        <div v-if="sidebarOpen" class="absolute inset-0 z-30 flex md:hidden">
            <div
                class="absolute inset-0 bg-black/40"
                @click="sidebarOpen = false"
            />
            <aside class="relative w-64 border-r bg-card">
                <button
                    type="button"
                    class="absolute top-3 right-3 rounded p-1 text-muted-foreground hover:bg-accent"
                    aria-label="Close"
                    @click="sidebarOpen = false"
                >
                    <X class="size-4" />
                </button>
                <ChatSidebar
                    :conversations="conversations"
                    :active-id="activeId"
                    @new="newChat"
                    @select="selectConversation"
                    @remove="removeConversation"
                />
            </aside>
        </div>

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Header -->
            <div
                class="flex items-center justify-between gap-2 border-b px-4 py-3"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:bg-accent md:hidden"
                        aria-label="Open chats"
                        @click="sidebarOpen = true"
                    >
                        <Menu class="size-5" />
                    </button>

                    <slot name="brand">
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
                                <span
                                    class="size-1.5 rounded-full bg-emerald-500"
                                />
                                Online
                            </p>
                        </div>
                    </slot>
                </div>

                <Select
                    :model-value="model"
                    @update:model-value="onModelChange"
                >
                    <SelectTrigger class="h-8 w-auto min-w-[160px] text-xs">
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
            <div
                ref="scrollRegion"
                class="flex-1 space-y-6 overflow-y-auto p-4"
            >
                <!-- Empty state -->
                <div
                    v-if="messages.length === 0"
                    class="relative flex h-full flex-col items-center justify-center px-4 text-center"
                >
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
                            <slot name="empty"
                                >Hi, I'm AiMe BOT. Ask me anything.</slot
                            >
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
