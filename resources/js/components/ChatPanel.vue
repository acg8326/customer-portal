<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ArrowUp,
    FileText,
    Image as ImageIcon,
    Menu,
    Paperclip,
    Sparkles,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
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

type Attachment = {
    name: string;
    mime: string;
};

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
    attachments?: Attachment[];
};

type ConversationSummary = {
    id: number;
    title: string;
};

type UploadConfig = {
    enabled: boolean;
    maxFiles: number;
    maxSizeKb: number;
    mimes: string;
};

type SkillOption = {
    id: number;
    name: string;
    icon: string | null;
};

const props = withDefaults(
    defineProps<{
        models: { value: string; label: string }[];
        defaultModel: string;
        conversations: ConversationSummary[];
        projectId?: number | null;
        fullBleed?: boolean;
        uploads?: UploadConfig;
        skills?: SkillOption[];
        mcpEnabled?: boolean;
    }>(),
    {
        projectId: null,
        fullBleed: false,
        uploads: () => ({
            enabled: false,
            maxFiles: 0,
            maxSizeKb: 0,
            mimes: '',
        }),
        skills: () => [],
        mcpEnabled: false,
    },
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
const streaming = ref(false);
const streamingTool = ref<string | null>(null);
const error = ref<string | null>(null);
const scrollRegion = ref<HTMLElement | null>(null);
const model = ref(initialModel());
const sidebarOpen = ref(false);
const promptTokens = ref(0);
const completionTokens = ref(0);
const tokenTotal = computed(() => promptTokens.value + completionTokens.value);
const pendingFiles = ref<File[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const skillId = ref<number | null>(null);
const skillValue = computed(() =>
    skillId.value === null ? 'none' : String(skillId.value),
);

function onSkillChange(value: unknown) {
    if (typeof value !== 'string') {
        return;
    }

    skillId.value = value === 'none' ? null : Number(value);
}

const acceptAttr = computed(() =>
    props.uploads.mimes
        .split(',')
        .map((e) => `.${e.trim()}`)
        .filter((e) => e.length > 1)
        .join(','),
);

function isImageMime(mime: string): boolean {
    return mime.startsWith('image/');
}

function openFilePicker() {
    fileInput.value?.click();
}

function onFilesSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = '';

    if (picked.length === 0) {
        return;
    }

    const maxBytes = props.uploads.maxSizeKb * 1024;
    const tooBig = picked.find((f) => f.size > maxBytes);

    if (tooBig) {
        error.value = `"${tooBig.name}" is too large (max ${Math.round(props.uploads.maxSizeKb / 1024)} MB).`;

        return;
    }

    const combined = [...pendingFiles.value, ...picked];

    if (combined.length > props.uploads.maxFiles) {
        error.value = `You can attach up to ${props.uploads.maxFiles} files.`;

        return;
    }

    error.value = null;
    pendingFiles.value = combined;
}

function removeFile(index: number) {
    pendingFiles.value = pendingFiles.value.filter((_, i) => i !== index);
}

function formatTokens(n: number): string {
    if (n >= 1000) {
        return `${(n / 1000).toFixed(n >= 10000 ? 0 : 1)}K`;
    }

    return `${n}`;
}

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

function baseHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
    };
}

function jsonHeaders(): Record<string, string> {
    return { 'Content-Type': 'application/json', ...baseHeaders() };
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
    promptTokens.value = 0;
    completionTokens.value = 0;
    pendingFiles.value = [];
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
        promptTokens.value = data.prompt_tokens ?? 0;
        completionTokens.value = data.completion_tokens ?? 0;
        skillId.value = data.skill_id ?? null;

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
    const files = pendingFiles.value;

    if ((!text && files.length === 0) || loading.value) {
        return;
    }

    error.value = null;
    messages.value.push({
        role: 'user',
        content: text,
        attachments: files.length
            ? files.map((f) => ({ name: f.name, mime: f.type }))
            : undefined,
    });
    draft.value = '';
    pendingFiles.value = [];
    loading.value = true;
    streaming.value = false;
    await scrollToBottom();

    // Empty assistant bubble we stream tokens into.
    const assistantIndex =
        messages.value.push({ role: 'assistant', content: '' }) - 1;
    let streamed = '';
    streamingTool.value = null;

    try {
        let res: Response;

        if (files.length) {
            const form = new FormData();

            if (activeId.value != null) {
                form.append('conversation_id', String(activeId.value));
            }

            if (props.projectId != null) {
                form.append('project_id', String(props.projectId));
            }

            form.append('content', text);
            form.append('model', model.value);

            if (skillId.value != null) {
                form.append('skill_id', String(skillId.value));
            }

            files.forEach((f) => form.append('files[]', f));

            res = await fetch('/chat/stream', {
                method: 'POST',
                headers: baseHeaders(),
                body: form,
            });
        } else {
            res = await fetch('/chat/stream', {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    conversation_id: activeId.value,
                    project_id: props.projectId,
                    content: text,
                    model: model.value,
                    skill_id: skillId.value,
                }),
            });
        }

        // Errors (validation, budget, rate limit, not-configured) arrive as
        // JSON, not a stream.
        if (!res.ok || !res.body) {
            const data = await res.json().catch(() => ({}));

            throw new Error(data.message ?? 'The assistant could not respond.');
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            // SSE frames are separated by a blank line.
            let sep = buffer.indexOf('\n\n');

            while (sep !== -1) {
                const frame = buffer.slice(0, sep).trim();
                buffer = buffer.slice(sep + 2);
                sep = buffer.indexOf('\n\n');

                if (frame === '') {
                    continue;
                }

                let evt = 'message';
                let dataStr = '';

                for (const line of frame.split('\n')) {
                    if (line.startsWith('event:')) {
                        evt = line.slice(6).trim();
                    } else if (line.startsWith('data:')) {
                        dataStr += line.slice(5).trim();
                    }
                }

                let payload: {
                    conversation_id?: number;
                    title?: string;
                    text?: string;
                    message?: string;
                    name?: string;
                    server?: string;
                    usage?: {
                        prompt_tokens?: number;
                        completion_tokens?: number;
                    };
                } = {};

                try {
                    payload = dataStr ? JSON.parse(dataStr) : {};
                } catch {
                    payload = {};
                }

                if (evt === 'meta') {
                    if (payload.conversation_id != null) {
                        activeId.value = payload.conversation_id;
                        bumpToTop(payload.conversation_id, payload.title ?? '');
                    }
                } else if (evt === 'tool') {
                    // An MCP tool is being called server-side.
                    streamingTool.value =
                        payload.server ?? payload.name ?? 'a tool';
                } else if (evt === 'delta') {
                    streaming.value = true;
                    streamingTool.value = null;
                    streamed += payload.text ?? '';
                    messages.value[assistantIndex].content = streamed;
                    await scrollToBottom();
                } else if (evt === 'done') {
                    if (payload.usage) {
                        promptTokens.value =
                            payload.usage.prompt_tokens ?? promptTokens.value;
                        completionTokens.value =
                            payload.usage.completion_tokens ??
                            completionTokens.value;
                    }
                } else if (evt === 'error') {
                    throw new Error(
                        payload.message ?? 'The assistant could not respond.',
                    );
                }
            }
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';

        // Drop the empty assistant bubble if nothing streamed before failing.
        if (streamed === '' && messages.value[assistantIndex]?.role === 'assistant') {
            messages.value.splice(assistantIndex, 1);
        }
    } finally {
        loading.value = false;
        streaming.value = false;
        streamingTool.value = null;
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

onMounted(() => {
    // Opened from a search result (…?c={id}) — load that conversation.
    const requested = Number(
        new URLSearchParams(window.location.search).get('c'),
    );

    if (Number.isInteger(requested) && requested > 0) {
        selectConversation(requested);
    }
});
</script>

<template>
    <div
        class="relative flex h-full w-full overflow-hidden bg-card"
        :class="fullBleed ? 'border-t' : 'rounded-2xl border shadow-sm'"
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
                            class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-sm"
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
            <div ref="scrollRegion" class="flex-1 overflow-y-auto p-4">
                <div class="flex h-full w-full flex-col space-y-6">
                    <!-- Empty state -->
                    <div
                        v-if="messages.length === 0"
                        class="relative flex h-full flex-col items-center justify-center px-4 text-center"
                    >
                        <div
                            class="pointer-events-none absolute top-1/2 left-1/2 z-0 size-80 -translate-x-1/2 -translate-y-[60%] rounded-full bg-gradient-to-br from-brand-gold/25 to-brand-navy/25 blur-[100px]"
                        />

                        <div class="relative z-10 flex flex-col items-center">
                            <div
                                class="mb-5 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-lg shadow-brand-gold/30"
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
                        :class="
                            m.role === 'user' ? 'justify-end' : 'justify-start'
                        "
                    >
                        <div
                            v-if="m.role === 'assistant'"
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-sm"
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
                            <div
                                v-if="m.attachments?.length"
                                class="flex flex-wrap gap-1.5"
                                :class="m.content ? 'mb-2' : ''"
                            >
                                <span
                                    v-for="(a, ai) in m.attachments"
                                    :key="ai"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs"
                                    :class="
                                        m.role === 'user'
                                            ? 'bg-white/15'
                                            : 'border border-border bg-background'
                                    "
                                >
                                    <component
                                        :is="
                                            isImageMime(a.mime)
                                                ? ImageIcon
                                                : FileText
                                        "
                                        class="size-3.5 shrink-0"
                                    />
                                    <span class="max-w-[12rem] truncate">{{
                                        a.name
                                    }}</span>
                                </span>
                            </div>
                            <template v-if="m.content">{{
                                m.content
                            }}</template>
                        </div>

                        <div
                            v-if="m.role === 'user'"
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary"
                        >
                            {{ userInitials }}
                        </div>
                    </div>

                    <!-- Typing indicator -->
                    <div
                        v-if="loading && !streaming"
                        class="flex items-start gap-3"
                    >
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-sm"
                        >
                            <Sparkles class="size-4" />
                        </div>
                        <div
                            class="flex items-center gap-2 rounded-2xl rounded-tl-sm bg-muted px-4 py-3 text-sm text-muted-foreground"
                        >
                            <Spinner class="size-4" />
                            <template v-if="streamingTool">
                                Using {{ streamingTool }}…
                            </template>
                            <template v-else> AiMe is thinking… </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error -->
            <div v-if="error" class="w-full px-4">
                <p
                    class="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ error }}
                </p>
            </div>

            <!-- Composer -->
            <div class="border-t border-border bg-card/60">
                <div class="w-full p-4">
                    <div
                        class="flex flex-col gap-2 rounded-2xl border border-input bg-background p-2 shadow-sm transition focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
                    >
                        <!-- Skill selector -->
                        <div
                            v-if="skills.length"
                            class="flex items-center gap-2 px-1 pt-1"
                        >
                            <span class="text-xs text-muted-foreground">
                                Skill
                            </span>
                            <Select
                                :model-value="skillValue"
                                @update:model-value="onSkillChange"
                            >
                                <SelectTrigger
                                    class="h-7 w-auto min-w-[150px] text-xs"
                                >
                                    <SelectValue placeholder="No skill" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none" class="text-xs">
                                        No skill
                                    </SelectItem>
                                    <SelectItem
                                        v-for="s in skills"
                                        :key="s.id"
                                        :value="String(s.id)"
                                        class="text-xs"
                                    >
                                        {{ s.icon ? s.icon + ' ' : ''
                                        }}{{ s.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Pending attachments -->
                        <div
                            v-if="pendingFiles.length"
                            class="flex flex-wrap gap-2 px-1 pt-1"
                        >
                            <div
                                v-for="(f, i) in pendingFiles"
                                :key="i"
                                class="flex items-center gap-1.5 rounded-lg border border-border bg-muted/60 px-2 py-1 text-xs"
                            >
                                <component
                                    :is="
                                        isImageMime(f.type)
                                            ? ImageIcon
                                            : FileText
                                    "
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <span class="max-w-[10rem] truncate">{{
                                    f.name
                                }}</span>
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground"
                                    aria-label="Remove file"
                                    @click="removeFile(i)"
                                >
                                    <X class="size-3" />
                                </button>
                            </div>
                        </div>

                        <div class="flex items-end gap-2">
                            <button
                                v-if="uploads.enabled"
                                type="button"
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                aria-label="Attach files"
                                title="Attach images or PDFs"
                                @click="openFilePicker"
                            >
                                <Paperclip class="size-5" />
                            </button>
                            <textarea
                                v-model="draft"
                                rows="1"
                                placeholder="Message AiMe BOT…"
                                class="max-h-40 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground"
                                @keydown="onKeydown"
                            />
                            <button
                                type="button"
                                :disabled="
                                    loading ||
                                    (draft.trim().length === 0 &&
                                        pendingFiles.length === 0)
                                "
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
                                aria-label="Send message"
                                @click="send"
                            >
                                <ArrowUp class="size-5" />
                            </button>
                        </div>
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            :accept="acceptAttr"
                            class="hidden"
                            @change="onFilesSelected"
                        />
                    </div>
                    <div
                        class="mt-2 flex items-center justify-between text-xs text-muted-foreground"
                    >
                        <span>Enter to send · Shift+Enter for a new line</span>
                        <span
                            v-if="tokenTotal > 0"
                            class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/60 px-2 py-0.5 font-medium"
                            :title="`${promptTokens.toLocaleString()} in · ${completionTokens.toLocaleString()} out`"
                        >
                            <span class="size-1.5 rounded-full bg-brand-gold" />
                            {{ formatTokens(tokenTotal) }} tokens
                        </span>
                    </div>
                </div>
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
