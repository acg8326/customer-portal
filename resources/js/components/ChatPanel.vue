<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ArrowUp,
    Copy,
    Download,
    FileText,
    FileType,
    FoldVertical,
    Image as ImageIcon,
    Menu,
    Paperclip,
    Sheet as SheetIcon,
    ShieldCheck,
    Sparkles,
    X,
    Zap,
} from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, nextTick, onMounted, ref } from 'vue';
import ChatSidebar from '@/components/ChatSidebar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
const compacted = ref(false);
const compacting = ref(false);

// Session toggle: skip the confirm-before-destructive-actions guardrail for
// connected tools. Persisted across reloads; sent with every message.
const AUTO_APPROVE_KEY = 'chat:autoApprove';
const autoApprove = ref(localStorage.getItem(AUTO_APPROVE_KEY) === '1');
// Turning auto-approve ON is guarded by a confirmation dialog; turning it OFF
// is immediate (returning to the safe default needs no warning).
const showAutoApproveConfirm = ref(false);

function setAutoApprove(on: boolean) {
    autoApprove.value = on;
    localStorage.setItem(AUTO_APPROVE_KEY, on ? '1' : '0');
}

// Clicking the switch: turning ON opens a confirmation first (state only flips
// once confirmed); turning OFF is immediate.
function toggleAutoApprove() {
    if (autoApprove.value) {
        setAutoApprove(false);
    } else {
        showAutoApproveConfirm.value = true;
    }
}

function confirmAutoApprove() {
    setAutoApprove(true);
    showAutoApproveConfirm.value = false;
}
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

// Render an assistant message from Markdown to sanitized HTML so tables, code
// blocks, lists, and links display properly. GFM is enabled (tables, ~~strike~~);
// `breaks` keeps single newlines as line breaks, matching the old pre-wrap feel.
// DOMPurify strips any HTML the model might emit — never trust model output raw.
marked.setOptions({ gfm: true, breaks: true });

function renderMarkdown(content: string): string {
    const html = marked.parse(content ?? '', { async: false }) as string;

    return DOMPurify.sanitize(html, {
        ADD_ATTR: ['target', 'rel'],
    });
}

// Whether an assistant answer contains a GFM table (so we offer sheet export).
function hasTable(content: string): boolean {
    return /\n[ \t]*\|?[ \t]*:?-{2,}:?[ \t]*(\|[ \t]*:?-{2,}:?[ \t]*)+\|?[ \t]*(\n|$)/.test(
        '\n' + content,
    );
}

function triggerDownload(blob: Blob, name: string) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

async function copyMessage(content: string) {
    try {
        await navigator.clipboard?.writeText(content);
    } catch {
        error.value = 'Could not copy to the clipboard.';
    }
}

function downloadMarkdown(content: string) {
    triggerDownload(
        new Blob([content], { type: 'text/markdown' }),
        `aime-answer-${Date.now()}.md`,
    );
}

// POST the answer to a server export endpoint and download the returned file.
async function exportAnswer(
    url: string,
    payload: Record<string, string>,
    fallbackName: string,
) {
    error.value = null;

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));

            throw new Error(data.message ?? 'Could not export that answer.');
        }

        const cd = res.headers.get('Content-Disposition') ?? '';
        const match = cd.match(/filename="([^"]+)"/);
        triggerDownload(await res.blob(), match ? match[1] : fallbackName);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Export failed.';
    }
}

function downloadPdf(content: string) {
    exportAnswer(
        '/chat/export/pdf',
        { content, title: 'AiMe answer' },
        'aime-answer.pdf',
    );
}

function downloadDocx(content: string) {
    exportAnswer(
        '/chat/export/docx',
        { content, title: 'AiMe answer' },
        'aime-answer.docx',
    );
}

function downloadSheet(content: string, format: 'csv' | 'xlsx') {
    exportAnswer(
        '/chat/export/sheet',
        { content, format },
        `aime-tables.${format}`,
    );
}

function openFilePicker() {
    fileInput.value?.click();
}

// Validate a batch of files against the upload limits and append them to the
// pending list. Shared by the file picker and clipboard paste.
function addFiles(picked: File[]) {
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

function onFilesSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = '';

    addFiles(picked);
}

// Paste images straight into the composer (Ctrl/Cmd+V). Only intercepts when a
// real image is on the clipboard — plain text pastes fall through to the
// textarea untouched. Clipboard images often arrive unnamed, so give them a
// friendly, unique name derived from their type.
function onPaste(event: ClipboardEvent) {
    if (!props.uploads.enabled) {
        return;
    }

    const items = Array.from(event.clipboardData?.items ?? []);
    const images = items.filter(
        (it) => it.kind === 'file' && it.type.startsWith('image/'),
    );

    if (images.length === 0) {
        return;
    }

    event.preventDefault();

    const files: File[] = [];

    images.forEach((it, i) => {
        const file = it.getAsFile();

        if (!file) {
            return;
        }

        const ext = (file.type.split('/')[1] || 'png').split('+')[0];
        const named =
            file.name && file.name !== 'image.png'
                ? file
                : new File([file], `pasted-image-${Date.now()}-${i}.${ext}`, {
                      type: file.type,
                  });

        files.push(named);
    });

    addFiles(files);
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
    compacted.value = false;
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
        compacted.value = data.compacted ?? false;

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
            form.append('auto_approve', autoApprove.value ? '1' : '0');

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
                    auto_approve: autoApprove.value,
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
        if (
            streamed === '' &&
            messages.value[assistantIndex]?.role === 'assistant'
        ) {
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

// Compact the active conversation: the server summarizes the transcript so far
// and future turns replay only newer messages, keeping context (and cost) down
// on long chats — like Claude's /compact. The visible transcript is untouched.
async function compactConversation() {
    if (activeId.value == null || compacting.value || loading.value) {
        return;
    }

    compacting.value = true;
    error.value = null;

    try {
        const res = await fetch(
            `/chat/conversations/${activeId.value}/compact`,
            {
                method: 'POST',
                headers: jsonHeaders(),
            },
        );
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            throw new Error(
                data.message ?? 'Could not compact this conversation.',
            );
        }

        compacted.value = true;

        if (data.usage) {
            promptTokens.value = data.usage.prompt_tokens ?? promptTokens.value;
            completionTokens.value =
                data.usage.completion_tokens ?? completionTokens.value;
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    } finally {
        compacting.value = false;
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

                <div class="flex items-center gap-2">
                    <div
                        v-if="mcpEnabled"
                        class="inline-flex h-8 items-center gap-2 rounded-md border px-2.5 transition-colors"
                        :class="
                            autoApprove
                                ? 'border-brand-gold/50 bg-brand-gold/10'
                                : 'border-border'
                        "
                        :title="
                            autoApprove
                                ? 'Auto-approve is ON — tool actions run without asking. Toggle off to require confirmation.'
                                : 'Tool actions ask for confirmation first. Toggle on to auto-approve them for this session.'
                        "
                    >
                        <component
                            :is="autoApprove ? Zap : ShieldCheck"
                            class="size-3.5"
                            :class="
                                autoApprove
                                    ? 'text-brand-gold'
                                    : 'text-muted-foreground'
                            "
                        />
                        <span
                            class="text-xs font-medium"
                            :class="
                                autoApprove
                                    ? 'text-brand-gold'
                                    : 'text-muted-foreground'
                            "
                        >
                            Auto-approve
                        </span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="autoApprove"
                            aria-label="Auto-approve tool actions for this session"
                            class="relative inline-flex h-4 w-7 shrink-0 cursor-pointer items-center rounded-full transition-colors"
                            :class="
                                autoApprove
                                    ? 'bg-brand-gold'
                                    : 'bg-muted-foreground/30'
                            "
                            @click="toggleAutoApprove"
                        >
                            <span
                                class="inline-block size-3 rounded-full bg-white shadow transition-transform"
                                :class="
                                    autoApprove
                                        ? 'translate-x-3.5'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                    </div>
                    <button
                        v-if="activeId != null && messages.length > 1"
                        type="button"
                        :disabled="compacting || loading"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50"
                        :title="
                            compacted
                                ? 'Already compacted — run again to fold in newer messages'
                                : 'Summarize this conversation to save context on long chats'
                        "
                        @click="compactConversation"
                    >
                        <FoldVertical class="size-3.5" />
                        {{
                            compacting
                                ? 'Compacting…'
                                : compacted
                                  ? 'Compacted'
                                  : 'Compact'
                        }}
                    </button>

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

                    <!-- Compacted notice -->
                    <div
                        v-if="compacted && messages.length"
                        class="flex justify-center"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/60 px-3 py-1 text-xs text-muted-foreground"
                        >
                            <FoldVertical class="size-3.5" />
                            Earlier messages compacted — AiMe uses a summary of
                            them to save context.
                        </span>
                    </div>

                    <!-- Messages -->
                    <template v-for="(m, i) in messages" :key="i">
                        <!-- Skip the empty assistant placeholder while a reply is
                         still streaming in — the typing indicator covers that
                         state, so an empty bubble would just be dead space. -->
                        <div
                            v-if="
                                m.role !== 'assistant' ||
                                m.content ||
                                m.attachments?.length
                            "
                            class="message-row flex items-start gap-3"
                            :class="
                                m.role === 'user'
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <div
                                v-if="m.role === 'assistant'"
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-sm"
                            >
                                <Sparkles class="size-4" />
                            </div>

                            <div
                                class="max-w-[80%] px-4 py-2.5 text-sm"
                                :class="
                                    m.role === 'user'
                                        ? 'rounded-2xl rounded-tr-sm bg-primary whitespace-pre-wrap text-primary-foreground'
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
                                <template v-if="m.content">
                                    <div
                                        v-if="m.role === 'assistant'"
                                        class="md"
                                        v-html="renderMarkdown(m.content)"
                                    />
                                    <template v-else>{{ m.content }}</template>
                                </template>

                                <!-- Export / copy actions on a finished assistant answer -->
                                <div
                                    v-if="
                                        m.role === 'assistant' &&
                                        m.content &&
                                        !(loading && i === messages.length - 1)
                                    "
                                    class="mt-2 flex flex-wrap items-center gap-1 border-t border-border/50 pt-2 text-xs text-muted-foreground"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                        title="Copy"
                                        @click="copyMessage(m.content)"
                                    >
                                        <Copy class="size-3.5" /> Copy
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                        title="Download as Markdown"
                                        @click="downloadMarkdown(m.content)"
                                    >
                                        <Download class="size-3.5" /> .md
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                        title="Download as PDF"
                                        @click="downloadPdf(m.content)"
                                    >
                                        <FileText class="size-3.5" /> PDF
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                        title="Download as Word (.docx)"
                                        @click="downloadDocx(m.content)"
                                    >
                                        <FileType class="size-3.5" /> Word
                                    </button>
                                    <template v-if="hasTable(m.content)">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                            title="Download tables as CSV"
                                            @click="
                                                downloadSheet(m.content, 'csv')
                                            "
                                        >
                                            <SheetIcon class="size-3.5" /> CSV
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                            title="Download tables as Excel (XLSX)"
                                            @click="
                                                downloadSheet(m.content, 'xlsx')
                                            "
                                        >
                                            <SheetIcon class="size-3.5" /> XLSX
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <div
                                v-if="m.role === 'user'"
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary"
                            >
                                {{ userInitials }}
                            </div>
                        </div>
                    </template>

                    <!-- Typing / working indicator. Stays visible while a tool
                         runs (streamingTool set), even after text has started,
                         so a slow create/update doesn't look frozen. -->
                    <div
                        v-if="loading && (!streaming || streamingTool)"
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
                                @paste="onPaste"
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
                        <span
                            >Enter to send · Shift+Enter for a new line<template
                                v-if="uploads.enabled"
                            >
                                · paste an image with Ctrl+V</template
                            ></span
                        >
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

        <!-- Confirm before enabling auto-approve for the session -->
        <Dialog v-model:open="showAutoApproveConfirm">
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle
                        >Auto-approve tool actions this session?</DialogTitle
                    >
                    <DialogDescription>
                        AiMe will run
                        <span class="font-medium"
                            >every connected-tool action</span
                        >
                        — including ones that create, update, delete, or send
                        data —
                        <span class="font-medium"
                            >without asking you to confirm first</span
                        >. This applies to the current chat session until you
                        toggle it back off. Only enable this if you trust the
                        requests you're about to make.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        class="bg-brand-gold text-white hover:bg-brand-gold/90"
                        @click="confirmAutoApprove"
                    >
                        Yes, auto-approve
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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

/* Rendered Markdown in assistant messages. Uses :deep() because the HTML is
   injected via v-html and would otherwise be out of scope. Colours come from
   the shared theme tokens so it works in light and dark. */
.md {
    line-height: 1.6;
    word-break: break-word;
}

.md :deep(> *:first-child) {
    margin-top: 0;
}

.md :deep(> *:last-child) {
    margin-bottom: 0;
}

.md :deep(p),
.md :deep(ul),
.md :deep(ol),
.md :deep(pre),
.md :deep(blockquote),
.md :deep(table) {
    margin: 0.6em 0;
}

.md :deep(ul),
.md :deep(ol) {
    padding-left: 1.35em;
}

.md :deep(li) {
    margin: 0.2em 0;
}

.md :deep(h1),
.md :deep(h2),
.md :deep(h3),
.md :deep(h4) {
    margin: 0.9em 0 0.4em;
    font-weight: 600;
    line-height: 1.3;
}

.md :deep(h1) {
    font-size: 1.3em;
}
.md :deep(h2) {
    font-size: 1.2em;
}
.md :deep(h3) {
    font-size: 1.1em;
}

.md :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.md :deep(strong) {
    font-weight: 600;
}

.md :deep(code) {
    font-family:
        ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono',
        monospace;
    font-size: 0.85em;
    background: color-mix(in srgb, var(--foreground) 10%, transparent);
    padding: 0.15em 0.35em;
    border-radius: 0.35rem;
}

.md :deep(pre) {
    background: color-mix(in srgb, var(--foreground) 8%, transparent);
    border: 1px solid var(--border);
    border-radius: 0.6rem;
    padding: 0.75em 0.9em;
    overflow-x: auto;
}

.md :deep(pre code) {
    background: transparent;
    padding: 0;
    font-size: 0.82em;
    line-height: 1.5;
}

.md :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 0.9em;
    color: var(--muted-foreground);
}

/* Tables — the main reason for this change. Scroll horizontally on overflow
   so wide tables never break the bubble layout. */
.md :deep(table) {
    display: block;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.9em;
}

.md :deep(th),
.md :deep(td) {
    border: 1px solid var(--border);
    padding: 0.4em 0.65em;
    text-align: left;
    vertical-align: top;
}

.md :deep(th) {
    background: color-mix(in srgb, var(--foreground) 6%, transparent);
    font-weight: 600;
}

.md :deep(hr) {
    border: none;
    border-top: 1px solid var(--border);
    margin: 1em 0;
}

.md :deep(img) {
    max-width: 100%;
    border-radius: 0.5rem;
}
</style>
