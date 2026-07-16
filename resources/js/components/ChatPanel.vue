<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    ArrowUp,
    Boxes,
    Brain,
    Check,
    ChevronDown,
    ChevronRight,
    Copy,
    Download,
    FileText,
    FileType,
    FoldVertical,
    Ghost,
    Globe,
    Image as ImageIcon,
    ImagePlus,
    Loader2,
    Lock,
    Menu,
    Mic,
    Paperclip,
    Pencil,
    RefreshCw,
    Square,
    Volume2,
    Share2,
    Sheet as SheetIcon,
    ShieldCheck,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
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
    // Set for stored images (uploaded or AI-generated) — rendered inline.
    url?: string | null;
};

type ChatMessage = {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    thinking?: string | null;
    feedback?: number | null;
    attachments?: Attachment[];
};

type ConversationSummary = {
    id: number;
    title: string;
    starred?: boolean;
};

// A tool call awaiting Approve/Cancel at the hard gate.
type PendingCall = {
    name: string;
    input: Record<string, unknown>;
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

type ProviderModel = { value: string; label: string; hint: string };

// A linked NetSuite account (users can hold several; chats pin one).
type NetsuiteAccountOption = {
    id: number;
    label: string;
    accountId: string;
    isDefault: boolean;
};

type Provider = {
    key: string;
    name: string;
    available: boolean;
    blurb: string;
    models: ProviderModel[];
};

const props = withDefaults(
    defineProps<{
        providers: Provider[];
        defaultModel: string;
        conversations: ConversationSummary[];
        projectId?: number | null;
        fullBleed?: boolean;
        uploads?: UploadConfig;
        skills?: SkillOption[];
        mcpEnabled?: boolean;
        netsuiteAccounts?: NetsuiteAccountOption[];
        webEnabled?: boolean;
        imageEnabled?: boolean;
        speechEnabled?: boolean;
        continuePrompt?: string;
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
        netsuiteAccounts: () => [],
        webEnabled: false,
        imageEnabled: false,
        speechEnabled: false,
        continuePrompt:
            'Continue exactly where you left off — do not repeat what you already wrote.',
    },
);

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name ?? 'there');
const userInitials = computed(() => getInitials(page.props.auth?.user?.name));

// --- NetSuite account picker -------------------------------------------------
// Shown only when the user has linked more than one NetSuite account. The
// selection is pinned on the conversation server-side, so every NetSuite
// query in this chat runs against that account only.
const showNetsuitePicker = computed(() => props.netsuiteAccounts.length > 1);

function defaultNetsuiteAccountId(): number | null {
    const accounts = props.netsuiteAccounts;

    return accounts.find((a) => a.isDefault)?.id ?? accounts[0]?.id ?? null;
}

const netsuiteAccountId = ref<number | null>(defaultNetsuiteAccountId());

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

// Flat view of every pickable model with its provider context.
const allModels = computed(() =>
    props.providers.flatMap((p) =>
        p.models.map((m) => ({
            ...m,
            provider: p.key,
            providerName: p.name,
            available: p.available,
        })),
    ),
);

function initialModel(): string {
    const saved = localStorage.getItem(MODEL_STORAGE_KEY);

    if (
        saved &&
        allModels.value.some((m) => m.value === saved && m.available)
    ) {
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

// Extended thinking toggle (like claude.ai's thinking mode). Persisted in the
// browser; sent with every message. The server only enables it on models that
// support adaptive thinking.
const THINKING_KEY = 'chat:thinking';
const thinkingOn = ref(localStorage.getItem(THINKING_KEY) === '1');

function toggleThinking() {
    thinkingOn.value = !thinkingOn.value;
    localStorage.setItem(THINKING_KEY, thinkingOn.value ? '1' : '0');
}

// Web search toggle (like claude.ai's). ON by default; persisted in the
// browser; sent with every message. OFF forces knowledge-base/tools-only
// answers and saves the web tools' tokens.
const WEB_KEY = 'chat:webSearch';
const webOn = ref(localStorage.getItem(WEB_KEY) !== '0');

function toggleWeb() {
    webOn.value = !webOn.value;
    localStorage.setItem(WEB_KEY, webOn.value ? '1' : '0');
}

// Private chat (like ChatGPT's temporary chat): nothing is saved — no
// conversation, no messages, no memory. The transcript lives only in this
// component and is resent with each turn; it's gone on refresh or toggle.
// Deliberately NOT persisted across reloads: private is an in-the-moment
// choice, not a sticky mode someone forgets they left on.
const privateOn = ref(false);

function togglePrivate() {
    privateOn.value = !privateOn.value;
    // Both directions start from a clean slate — a private transcript must
    // not leak into a saved chat, and vice versa.
    newChat();
}

// Message font size (Settings → General). Read once on mount — navigating
// back from Settings remounts the chat page, picking up changes.
const FONT_SIZE_KEY = 'chat:fontSize';
const fontSizeClass =
    {
        sm: 'text-[0.8125rem]',
        base: 'text-sm',
        lg: 'text-base',
    }[localStorage.getItem(FONT_SIZE_KEY) ?? 'base'] ?? 'text-sm';

// Team share link for the active conversation (null = not shared).
const shareUrl = ref<string | null>(null);
const showShareDialog = ref(false);
const shareBusy = ref(false);
const shareCopied = ref(false);

async function toggleShare() {
    if (activeId.value == null || shareBusy.value) {
        return;
    }

    shareBusy.value = true;
    shareCopied.value = false;

    try {
        const res = await fetch(`/chat/conversations/${activeId.value}/share`, {
            method: 'POST',
            headers: jsonHeaders(),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error();
        }

        shareUrl.value = data.url ?? null;
    } catch {
        error.value = 'Could not update sharing.';
    } finally {
        shareBusy.value = false;
    }
}

async function copyShareLink() {
    if (!shareUrl.value) {
        return;
    }

    await navigator.clipboard.writeText(shareUrl.value);
    shareCopied.value = true;
    setTimeout(() => (shareCopied.value = false), 2000);
}

// stop_reason of the last completed reply — 'max_tokens' shows "Continue".
const lastStopReason = ref<string | null>(null);

// Tool calls paused at the hard gate, awaiting Approve / Cancel.
const pendingApproval = ref<PendingCall[] | null>(null);

// Edit-and-resend state (pencil on the last user message).
const editingIndex = ref<number | null>(null);
const editDraft = ref('');
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

// --- Grouped model picker (LibreChat-style: providers → models) -----------------

const modelMenuOpen = ref(false);
const menuProviderKey = ref(props.providers[0]?.key ?? '');
// Locked provider the user tried to pick from — drives the request dialog.
const requestTarget = ref<{ provider: Provider; model: ProviderModel } | null>(
    null,
);
const requestSending = ref(false);

const menuProvider = computed(
    () =>
        props.providers.find((p) => p.key === menuProviderKey.value) ??
        props.providers[0],
);

const currentModel = computed(() =>
    allModels.value.find((m) => m.value === model.value),
);

const currentModelLabel = computed(
    () => currentModel.value?.label ?? model.value,
);

// Claude-only features (web, thinking, attachments, connected tools) grey
// out when a plain-chat provider is selected.
const modelIsClaude = computed(
    () => (currentModel.value?.provider ?? 'anthropic') === 'anthropic',
);

function openModelMenu() {
    modelMenuOpen.value = !modelMenuOpen.value;
    menuProviderKey.value =
        currentModel.value?.provider ?? props.providers[0]?.key ?? '';
}

function pickModel(provider: Provider, m: ProviderModel) {
    if (!provider.available) {
        requestTarget.value = { provider, model: m };

        return;
    }

    model.value = m.value;
    localStorage.setItem(MODEL_STORAGE_KEY, m.value);
    modelMenuOpen.value = false;
}

// --- Image generation mode (composer toggle) ------------------------------------

const imageMode = ref(false);

function toggleImageMode() {
    if (!props.imageEnabled) {
        // Locked → same request-access flow as locked chat providers.
        requestTarget.value = {
            provider: {
                key: 'openai',
                name: 'OpenAI',
                available: false,
                blurb: '',
                models: [],
            },
            model: {
                value: 'gpt-image-1',
                label: 'Image generation',
                hint: '',
            },
        };

        return;
    }

    imageMode.value = !imageMode.value;
}

async function sendImage() {
    const prompt = draft.value.trim();

    if (!prompt || loading.value) {
        return;
    }

    error.value = null;
    messages.value.push({ role: 'user', content: prompt });
    draft.value = '';
    loading.value = true;

    const assistantIndex =
        messages.value.push({ role: 'assistant', content: '' }) - 1;

    await scrollToBottom();

    try {
        const res = await fetch('/chat/image', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ prompt, conversation_id: activeId.value }),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.message ?? 'Image generation failed.');
        }

        messages.value[assistantIndex] = data.message;

        if (activeId.value == null && data.conversation_id != null) {
            activeId.value = data.conversation_id;
            bumpToTop(data.conversation_id, data.title ?? '');
        }
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Image generation failed.';
        messages.value.splice(assistantIndex, 1);
    } finally {
        loading.value = false;
        await scrollToBottom();
    }
}

// --- Speech: dictation (mic → text) + read-aloud ---------------------------------

const recording = ref(false);
const transcribing = ref(false);
let mediaRecorder: MediaRecorder | null = null;
let audioChunks: Blob[] = [];

async function toggleRecording() {
    if (!props.speechEnabled) {
        requestTarget.value = {
            provider: {
                key: 'openai',
                name: 'OpenAI',
                available: false,
                blurb: '',
                models: [],
            },
            model: {
                value: 'speech',
                label: 'Voice dictation & read-aloud',
                hint: '',
            },
        };

        return;
    }

    if (recording.value) {
        mediaRecorder?.stop();

        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
        });

        audioChunks = [];
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size) {
                audioChunks.push(e.data);
            }
        };
        mediaRecorder.onstop = async () => {
            stream.getTracks().forEach((t) => t.stop());
            recording.value = false;

            const blob = new Blob(audioChunks, {
                type: mediaRecorder?.mimeType || 'audio/webm',
            });

            if (!blob.size) {
                return;
            }

            transcribing.value = true;

            try {
                const form = new FormData();

                form.append('audio', blob, 'recording.webm');

                const res = await fetch('/chat/transcribe', {
                    method: 'POST',
                    headers: baseHeaders(),
                    body: form,
                });
                const data = await res.json();

                if (!res.ok) {
                    throw new Error(data.message ?? "Couldn't transcribe.");
                }

                draft.value = draft.value
                    ? `${draft.value.trimEnd()} ${data.text}`
                    : data.text;
            } catch (e) {
                error.value =
                    e instanceof Error ? e.message : "Couldn't transcribe.";
            } finally {
                transcribing.value = false;
            }
        };
        mediaRecorder.start();
        recording.value = true;
    } catch {
        error.value = 'Microphone unavailable — check browser permissions.';
    }
}

const speakingId = ref<number | null>(null);
let audioEl: HTMLAudioElement | null = null;

async function toggleSpeak(m: ChatMessage) {
    if (m.id == null) {
        return;
    }

    // Clicking the playing message stops it.
    if (speakingId.value === m.id) {
        audioEl?.pause();
        speakingId.value = null;

        return;
    }

    audioEl?.pause();
    speakingId.value = m.id;

    try {
        const res = await fetch('/chat/speech', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ message_id: m.id }),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));

            throw new Error(data.message ?? "Couldn't generate audio.");
        }

        const blob = await res.blob();

        // The user may have clicked stop (or another message) meanwhile.
        if (speakingId.value !== m.id) {
            return;
        }

        audioEl = new Audio(URL.createObjectURL(blob));
        audioEl.onended = () => {
            if (speakingId.value === m.id) {
                speakingId.value = null;
            }
        };
        await audioEl.play();
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : "Couldn't generate audio.";
        speakingId.value = null;
    }
}

function sendApiRequest() {
    const target = requestTarget.value;

    if (!target || requestSending.value) {
        return;
    }

    requestSending.value = true;

    router.post(
        '/feedback',
        {
            type: 'api_request',
            message: `Please enable ${target.provider.name} for the chat — I'd like to use ${target.model.label} (${target.model.value}). An admin needs to add the provider's API key to the server.`,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                requestTarget.value = null;
                modelMenuOpen.value = false;
            },
            onFinish: () => (requestSending.value = false),
        },
    );
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
    lastStopReason.value = null;
    editingIndex.value = null;
    pendingApproval.value = null;
    shareUrl.value = null;
    showShareDialog.value = false;
    netsuiteAccountId.value = defaultNetsuiteAccountId();
}

async function selectConversation(id: number) {
    sidebarOpen.value = false;
    // Opening a saved chat leaves private mode (and drops its transcript).
    privateOn.value = false;

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
        lastStopReason.value = null;
        editingIndex.value = null;
        pendingApproval.value = data.pending_approval ?? null;
        shareUrl.value = data.share_url ?? null;
        // Restore the chat's pinned NetSuite account (falls back to default).
        netsuiteAccountId.value =
            data.netsuite_connection_id ?? defaultNetsuiteAccountId();

        if (typeof data.model === 'string') {
            model.value = data.model;
        }

        await scrollToBottom();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    }
}

// Starred chats stay pinned above the rest; within each group the existing
// (recency) order is kept — Array.sort is stable.
function sortConversations() {
    conversations.value = [...conversations.value].sort(
        (a, b) => Number(b.starred ?? false) - Number(a.starred ?? false),
    );
}

function bumpToTop(id: number, title: string) {
    const starred =
        conversations.value.find((c) => c.id === id)?.starred ?? false;

    conversations.value = [
        { id, title, starred },
        ...conversations.value.filter((c) => c.id !== id),
    ];
    sortConversations();
}

// Toggle a star optimistically; revert if the server disagrees.
async function toggleStar(id: number) {
    const c = conversations.value.find((x) => x.id === id);

    if (!c) {
        return;
    }

    c.starred = !c.starred;
    sortConversations();

    try {
        const res = await fetch(`/chat/conversations/${id}/star`, {
            method: 'POST',
            headers: jsonHeaders(),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error();
        }

        c.starred = data.starred === true;
    } catch {
        c.starred = !c.starred;
    }

    sortConversations();
}

type SendOptions = {
    // Regenerate the last assistant reply (no new user message).
    retry?: boolean;
    // Edit-and-resend: replace the last exchange with this text.
    replaceLast?: boolean;
    // Send this text instead of the composer draft (e.g. "Continue").
    text?: string;
};

async function send(opts: SendOptions = {}) {
    const isRetry = opts.retry === true;

    // Image mode hijacks plain sends only — retry/edit/continue stay chat.
    if (
        imageMode.value &&
        !isRetry &&
        opts.text == null &&
        opts.replaceLast !== true
    ) {
        void sendImage();

        return;
    }

    // Private chats have no server-side conversation to retry against, and
    // attachments would have to be stored — both stay off in private mode.
    if (privateOn.value && isRetry) {
        return;
    }

    const text = isRetry ? '' : (opts.text ?? draft.value).trim();
    const files =
        isRetry || opts.text != null || privateOn.value
            ? []
            : pendingFiles.value;

    if ((!text && files.length === 0 && !isRetry) || loading.value) {
        return;
    }

    error.value = null;
    lastStopReason.value = null;
    editingIndex.value = null;
    // A new turn supersedes any paused tool approval (the server drops it too).
    pendingApproval.value = null;

    if (isRetry) {
        // Drop the last assistant bubble locally; the server does the same.
        const last = messages.value[messages.value.length - 1];

        if (last?.role === 'assistant') {
            messages.value.pop();
        }
    } else {
        if (opts.replaceLast) {
            // Drop the last exchange locally; the server does the same.
            if (
                messages.value[messages.value.length - 1]?.role === 'assistant'
            ) {
                messages.value.pop();
            }

            if (messages.value[messages.value.length - 1]?.role === 'user') {
                messages.value.pop();
            }
        }

        messages.value.push({
            role: 'user',
            content: text,
            attachments: files.length
                ? files.map((f) => ({ name: f.name, mime: f.type }))
                : undefined,
        });
    }

    if (opts.text == null && !isRetry) {
        draft.value = '';
        pendingFiles.value = [];
    }

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
            form.append('thinking', thinkingOn.value ? '1' : '0');
            form.append('web', webOn.value ? '1' : '0');

            if (showNetsuitePicker.value && netsuiteAccountId.value != null) {
                form.append(
                    'netsuite_connection_id',
                    String(netsuiteAccountId.value),
                );
            }

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
            // Private mode replays the browser-held transcript instead of a
            // conversation id: every completed turn before the user message
            // we just pushed (the placeholder sits at assistantIndex).
            const body = privateOn.value
                ? {
                      content: text,
                      model: model.value,
                      skill_id: skillId.value,
                      thinking: thinkingOn.value,
                      web: webOn.value,
                      private: true,
                      history: messages.value
                          .slice(0, assistantIndex - 1)
                          .filter((m) => m.content)
                          .map((m) => ({ role: m.role, content: m.content })),
                  }
                : {
                      conversation_id: activeId.value,
                      project_id: props.projectId,
                      content: text,
                      model: model.value,
                      skill_id: skillId.value,
                      auto_approve: autoApprove.value,
                      thinking: thinkingOn.value,
                      web: webOn.value,
                      retry: isRetry,
                      replace_last: opts.replaceLast === true,
                      // Only sent when the picker is visible — single-account
                      // users keep the server-side default.
                      ...(showNetsuitePicker.value &&
                      netsuiteAccountId.value != null
                          ? { netsuite_connection_id: netsuiteAccountId.value }
                          : {}),
                  };

            res = await fetch('/chat/stream', {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify(body),
            });
        }

        // Errors (validation, budget, rate limit, not-configured) arrive as
        // JSON, not a stream.
        if (!res.ok || !res.body) {
            const data = await res.json().catch(() => ({}));

            throw new Error(data.message ?? 'The assistant could not respond.');
        }

        streamed = await consumeStream(res, assistantIndex);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';

        // Drop the assistant bubble if nothing arrived before failing.
        if (
            streamed === '' &&
            !messages.value[assistantIndex]?.thinking &&
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

// Read a chat SSE stream (from /chat/stream or the tool-decision endpoint)
// into the assistant bubble at assistantIndex. Returns the streamed text.
async function consumeStream(
    res: Response,
    assistantIndex: number,
): Promise<string> {
    if (!res.body) {
        return '';
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let streamed = '';

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
                message_id?: number;
                stop_reason?: string | null;
                name?: string;
                server?: string;
                label?: string;
                calls?: PendingCall[];
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
            } else if (evt === 'title') {
                // Auto-generated title after the first exchange.
                if (activeId.value != null && payload.title) {
                    bumpToTop(activeId.value, payload.title);
                }
            } else if (evt === 'tool') {
                // A tool is running server-side (NetSuite/Composio/MCP/web
                // search) — show what the assistant is doing while it waits.
                streamingTool.value =
                    payload.label ??
                    `Using ${payload.server ?? payload.name ?? 'a tool'}`;
            } else if (evt === 'approval') {
                // Hard gate: the turn paused before a destructive tool call.
                pendingApproval.value = payload.calls ?? [];
            } else if (evt === 'thinking') {
                // Extended thinking: fills the collapsible block.
                streaming.value = true;
                messages.value[assistantIndex].thinking =
                    (messages.value[assistantIndex].thinking ?? '') +
                    (payload.text ?? '');
                await scrollToBottom();
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

                if (payload.message_id != null) {
                    messages.value[assistantIndex].id = payload.message_id;
                }

                lastStopReason.value = payload.stop_reason ?? null;
            } else if (evt === 'error') {
                throw new Error(
                    payload.message ?? 'The assistant could not respond.',
                );
            }
        }
    }

    return streamed;
}

// Approve or cancel the tool calls paused at the hard gate. The decision
// endpoint streams the continuation (or the cancellation note) as SSE.
async function decideTools(approve: boolean) {
    if (loading.value || activeId.value == null || !pendingApproval.value) {
        return;
    }

    pendingApproval.value = null;
    error.value = null;
    loading.value = true;
    streaming.value = false;
    streamingTool.value = null;

    // Continue into the last assistant bubble, or open a fresh one.
    let assistantIndex = messages.value.length - 1;

    if (messages.value[assistantIndex]?.role !== 'assistant') {
        assistantIndex =
            messages.value.push({ role: 'assistant', content: '' }) - 1;
    }

    try {
        const res = await fetch(
            `/chat/conversations/${activeId.value}/tools/decision`,
            {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({ approve }),
            },
        );

        if (!res.ok || !res.body) {
            const data = await res.json().catch(() => ({}));

            throw new Error(data.message ?? 'Could not apply your decision.');
        }

        await consumeStream(res, assistantIndex);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    } finally {
        loading.value = false;
        streaming.value = false;
        streamingTool.value = null;
        await scrollToBottom();
    }
}

// Regenerate the last assistant reply (like claude.ai's retry).
function retryLast() {
    if (loading.value || activeId.value == null) {
        return;
    }

    send({ retry: true });
}

// Resume a reply that was cut off at the max-token cap.
function continueReply() {
    if (loading.value) {
        return;
    }

    send({ text: props.continuePrompt });
}

function startEdit(index: number) {
    editingIndex.value = index;
    editDraft.value = messages.value[index]?.content ?? '';
}

function cancelEdit() {
    editingIndex.value = null;
    editDraft.value = '';
}

function submitEdit() {
    const text = editDraft.value.trim();

    if (!text || loading.value) {
        return;
    }

    editDraft.value = '';
    send({ replaceLast: true, text });
}

// Thumbs on an assistant reply — clicking the active rating clears it.
async function rateMessage(m: ChatMessage, rating: 'up' | 'down') {
    if (m.id == null) {
        return;
    }

    const target = m.feedback === (rating === 'up' ? 1 : -1) ? 'none' : rating;

    try {
        const res = await fetch(`/chat/messages/${m.id}/feedback`, {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ rating: target }),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error('Could not save your feedback.');
        }

        m.feedback = data.feedback ?? null;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
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
                @star="toggleStar"
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
                    @star="toggleStar"
                />
            </aside>
        </div>

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Header -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
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

                <!-- Toolbar: wraps under the brand row on narrow screens;
                 labels collapse to icons below sm so nothing gets cramped -->
                <div
                    class="flex min-w-0 flex-wrap items-center justify-end gap-1.5 sm:gap-2"
                >
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors"
                        :class="
                            privateOn
                                ? 'border-brand-gold/50 bg-brand-gold/10 text-brand-gold'
                                : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                        :title="
                            privateOn
                                ? 'Private chat is ON — nothing is saved; the conversation disappears when you leave. Click to go back to normal chats.'
                                : 'Start a private chat — messages are not saved to your history or the database.'
                        "
                        @click="togglePrivate"
                    >
                        <Ghost class="size-3.5" />
                        <span class="hidden sm:inline">Private</span>
                    </button>
                    <button
                        v-if="webEnabled"
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                        :class="
                            webOn && modelIsClaude
                                ? 'border-brand-gold/50 bg-brand-gold/10 text-brand-gold'
                                : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                        :disabled="!modelIsClaude"
                        :title="
                            !modelIsClaude
                                ? 'Web search works with Claude models only'
                                : webOn
                                  ? 'Web search is ON — answers can use live web results with sources. Click to turn off.'
                                  : 'Web search is OFF — answers use only the knowledge base and connected tools. Click to turn on.'
                        "
                        @click="toggleWeb"
                    >
                        <Globe class="size-3.5" />
                        <span class="hidden sm:inline">Web</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                        :class="
                            thinkingOn && modelIsClaude
                                ? 'border-brand-gold/50 bg-brand-gold/10 text-brand-gold'
                                : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                        :disabled="!modelIsClaude"
                        :title="
                            !modelIsClaude
                                ? 'Extended thinking works with Claude models only'
                                : thinkingOn
                                  ? 'Extended thinking is ON — the thought process shows in a collapsible block. Click to turn off.'
                                  : 'Turn on extended thinking — the assistant reasons longer and shows its thought process.'
                        "
                        @click="toggleThinking"
                    >
                        <Brain class="size-3.5" />
                        <span class="hidden sm:inline">Thinking</span>
                    </button>
                    <div
                        v-if="showNetsuitePicker"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border border-brand-gold/50 bg-brand-gold/10 px-2.5 text-xs font-medium text-brand-gold"
                        title="Which NetSuite account this chat queries — every NetSuite question here runs against the selected account only. The choice is saved on this chat."
                    >
                        <Boxes class="size-3.5" />
                        <select
                            v-model="netsuiteAccountId"
                            aria-label="NetSuite account for this chat"
                            class="h-full max-w-36 cursor-pointer truncate bg-transparent text-xs font-medium outline-none"
                        >
                            <option
                                v-for="a in netsuiteAccounts"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.label }}
                            </option>
                        </select>
                    </div>
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
                            class="hidden text-xs font-medium sm:inline"
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
                        v-if="activeId != null"
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors"
                        :class="
                            shareUrl
                                ? 'border-brand-gold/50 bg-brand-gold/10 text-brand-gold'
                                : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                        :title="
                            shareUrl
                                ? 'Shared — any logged-in member with the link can view. Click to manage.'
                                : 'Share a read-only link with your team'
                        "
                        @click="showShareDialog = true"
                    >
                        <Share2 class="size-3.5" />
                        <span class="hidden sm:inline">{{
                            shareUrl ? 'Shared' : 'Share'
                        }}</span>
                    </button>
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
                        <span class="hidden sm:inline">{{
                            compacting
                                ? 'Compacting…'
                                : compacted
                                  ? 'Compacted'
                                  : 'Compact'
                        }}</span>
                    </button>

                    <!-- Grouped model picker: providers left, models right -->
                    <div class="relative">
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium transition-colors hover:bg-accent sm:min-w-[160px]"
                            :title="
                                modelIsClaude
                                    ? 'Choose a model'
                                    : 'Plain-chat model — tools, web search, thinking & files need Claude'
                            "
                            @click="openModelMenu"
                        >
                            <span class="truncate">{{
                                currentModelLabel
                            }}</span>
                            <ChevronDown
                                class="ml-auto size-3.5 text-muted-foreground"
                            />
                        </button>

                        <template v-if="modelMenuOpen">
                            <div
                                class="fixed inset-0 z-40"
                                @click="modelMenuOpen = false"
                            />
                            <div
                                class="absolute top-full right-0 z-50 mt-1 flex max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border bg-popover shadow-lg"
                            >
                                <!-- Providers -->
                                <div
                                    class="w-40 shrink-0 border-r py-1 sm:w-48"
                                >
                                    <button
                                        v-for="p in providers"
                                        :key="p.key"
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium hover:bg-accent"
                                        :class="
                                            menuProviderKey === p.key
                                                ? 'bg-accent'
                                                : ''
                                        "
                                        @mouseenter="menuProviderKey = p.key"
                                        @click="menuProviderKey = p.key"
                                    >
                                        <span class="min-w-0 flex-1 truncate">
                                            {{ p.name }}
                                        </span>
                                        <Lock
                                            v-if="!p.available"
                                            class="size-3 shrink-0 text-muted-foreground"
                                        />
                                        <ChevronRight
                                            v-else
                                            class="size-3 shrink-0 text-muted-foreground"
                                        />
                                    </button>
                                </div>

                                <!-- Models of the hovered provider -->
                                <div
                                    v-if="menuProvider"
                                    class="max-h-80 w-60 overflow-y-auto py-1 sm:w-72"
                                >
                                    <p
                                        class="border-b px-3 py-2 text-[11px] leading-snug text-muted-foreground"
                                    >
                                        {{
                                            menuProvider.available
                                                ? menuProvider.blurb
                                                : 'Not enabled yet — picking a model sends an access request to your admin.'
                                        }}
                                    </p>
                                    <button
                                        v-for="m in menuProvider.models"
                                        :key="m.value"
                                        type="button"
                                        class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-accent"
                                        @click="pickModel(menuProvider, m)"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="truncate text-xs font-medium"
                                            >
                                                {{ m.label }}
                                            </p>
                                            <p
                                                v-if="m.hint"
                                                class="truncate text-[11px] text-muted-foreground"
                                            >
                                                {{ m.hint }}
                                            </p>
                                        </div>
                                        <Check
                                            v-if="model === m.value"
                                            class="mt-0.5 size-3.5 shrink-0 text-brand-gold"
                                        />
                                        <Lock
                                            v-else-if="!menuProvider.available"
                                            class="mt-0.5 size-3 shrink-0 text-muted-foreground"
                                        />
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
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
                            <span
                                v-if="privateOn"
                                class="mt-4 inline-flex items-center gap-1.5 rounded-full border border-brand-gold/40 bg-brand-gold/10 px-3 py-1 text-xs text-brand-gold"
                            >
                                <Ghost class="size-3.5" />
                                Private chat — messages aren't saved and
                                disappear when you leave.
                            </span>
                        </div>
                    </div>

                    <!-- Private-chat notice -->
                    <div
                        v-if="privateOn && messages.length"
                        class="flex justify-center"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-brand-gold/40 bg-brand-gold/10 px-3 py-1 text-xs text-brand-gold"
                        >
                            <Ghost class="size-3.5" />
                            Private chat — messages aren't saved and disappear
                            when you leave.
                        </span>
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
                                m.thinking ||
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
                                class="max-w-[80%] px-4 py-2.5"
                                :class="[
                                    fontSizeClass,
                                    m.role === 'user'
                                        ? 'rounded-2xl rounded-tr-sm bg-primary whitespace-pre-wrap text-primary-foreground'
                                        : 'rounded-2xl rounded-tl-sm bg-muted text-foreground',
                                ]"
                            >
                                <!-- Stored images (uploaded or generated) render inline -->
                                <div
                                    v-if="m.attachments?.some((a) => a.url)"
                                    class="flex flex-wrap gap-2"
                                    :class="m.content ? 'mb-2' : ''"
                                >
                                    <a
                                        v-for="(a, ai) in m.attachments.filter(
                                            (a) => a.url,
                                        )"
                                        :key="'img-' + ai"
                                        :href="a.url ?? undefined"
                                        target="_blank"
                                        rel="noopener"
                                        title="Open full size"
                                    >
                                        <img
                                            :src="a.url ?? undefined"
                                            :alt="a.name"
                                            loading="lazy"
                                            class="max-h-72 max-w-full rounded-lg border border-border/60"
                                        />
                                    </a>
                                </div>
                                <div
                                    v-if="m.attachments?.some((a) => !a.url)"
                                    class="flex flex-wrap gap-1.5"
                                    :class="m.content ? 'mb-2' : ''"
                                >
                                    <span
                                        v-for="(a, ai) in m.attachments.filter(
                                            (a) => !a.url,
                                        )"
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
                                <!-- Extended thinking: collapsible thought process -->
                                <details
                                    v-if="m.role === 'assistant' && m.thinking"
                                    class="mb-2 rounded-lg border border-border/60 bg-background/60"
                                    :open="!m.content"
                                >
                                    <summary
                                        class="flex cursor-pointer items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-muted-foreground select-none"
                                    >
                                        <Brain class="size-3.5" />
                                        Thought process
                                    </summary>
                                    <div
                                        class="border-t border-border/60 px-2.5 py-2 text-xs whitespace-pre-wrap text-muted-foreground"
                                    >
                                        {{ m.thinking }}
                                    </div>
                                </details>

                                <!-- Edit-and-resend (last user message) -->
                                <div
                                    v-if="editingIndex === i"
                                    class="min-w-[18rem]"
                                >
                                    <textarea
                                        v-model="editDraft"
                                        rows="3"
                                        class="w-full resize-y rounded-md border border-white/30 bg-white/10 px-2.5 py-1.5 text-sm text-primary-foreground outline-none placeholder:text-primary-foreground/50"
                                        @keydown.enter.exact.prevent="
                                            submitEdit()
                                        "
                                        @keydown.esc="cancelEdit()"
                                    />
                                    <div class="mt-1.5 flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded px-2 py-0.5 text-xs hover:bg-white/15"
                                            @click="cancelEdit"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded bg-white/20 px-2 py-0.5 text-xs font-medium hover:bg-white/30"
                                            @click="submitEdit"
                                        >
                                            Send
                                        </button>
                                    </div>
                                </div>
                                <template v-else-if="m.content">
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
                                        v-if="speechEnabled && m.id != null"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                        :class="
                                            speakingId === m.id
                                                ? 'text-brand-gold'
                                                : ''
                                        "
                                        :title="
                                            speakingId === m.id
                                                ? 'Stop'
                                                : 'Read this reply aloud'
                                        "
                                        @click="toggleSpeak(m)"
                                    >
                                        <component
                                            :is="
                                                speakingId === m.id
                                                    ? Square
                                                    : Volume2
                                            "
                                            class="size-3.5"
                                        />
                                        {{
                                            speakingId === m.id
                                                ? 'Stop'
                                                : 'Listen'
                                        }}
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

                                    <span class="mx-0.5 h-4 w-px bg-border" />

                                    <button
                                        v-if="
                                            i === messages.length - 1 &&
                                            !privateOn
                                        "
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-background hover:text-foreground"
                                        title="Regenerate this reply"
                                        :disabled="loading"
                                        @click="retryLast"
                                    >
                                        <RefreshCw class="size-3.5" /> Retry
                                    </button>
                                    <button
                                        v-if="m.id != null"
                                        type="button"
                                        class="inline-flex items-center rounded px-1.5 py-0.5 hover:bg-background"
                                        :class="
                                            m.feedback === 1
                                                ? 'text-emerald-500'
                                                : 'hover:text-foreground'
                                        "
                                        title="Good reply"
                                        @click="rateMessage(m, 'up')"
                                    >
                                        <ThumbsUp class="size-3.5" />
                                    </button>
                                    <button
                                        v-if="m.id != null"
                                        type="button"
                                        class="inline-flex items-center rounded px-1.5 py-0.5 hover:bg-background"
                                        :class="
                                            m.feedback === -1
                                                ? 'text-destructive'
                                                : 'hover:text-foreground'
                                        "
                                        title="Bad reply"
                                        @click="rateMessage(m, 'down')"
                                    >
                                        <ThumbsDown class="size-3.5" />
                                    </button>
                                </div>

                                <!-- Continue a reply cut off at the token cap -->
                                <div
                                    v-if="
                                        m.role === 'assistant' &&
                                        i === messages.length - 1 &&
                                        lastStopReason === 'max_tokens' &&
                                        !loading
                                    "
                                    class="mt-2"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-gold/50 bg-brand-gold/10 px-2.5 py-1 text-xs font-medium text-brand-gold hover:bg-brand-gold/20"
                                        @click="continueReply"
                                    >
                                        <ArrowUp class="size-3.5 rotate-90" />
                                        Continue — the reply was cut off at the
                                        length limit
                                    </button>
                                </div>
                            </div>

                            <!-- Edit-and-resend pencil (last user message) -->
                            <button
                                v-if="
                                    m.role === 'user' &&
                                    i >= messages.length - 2 &&
                                    editingIndex === null &&
                                    !loading
                                "
                                type="button"
                                class="mt-2 self-start rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                title="Edit and resend"
                                @click="startEdit(i)"
                            >
                                <Pencil class="size-3.5" />
                            </button>

                            <div
                                v-if="m.role === 'user'"
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary"
                            >
                                {{ userInitials }}
                            </div>
                        </div>
                    </template>

                    <!-- Tool approval card (hard gate): the turn is paused
                         before a destructive tool call until the user decides. -->
                    <div
                        v-if="pendingApproval?.length && !loading"
                        class="flex items-start gap-3"
                    >
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-sm"
                        >
                            <ShieldCheck class="size-4" />
                        </div>
                        <div
                            class="max-w-[80%] rounded-2xl rounded-tl-sm border border-brand-gold/50 bg-brand-gold/5 px-4 py-3 text-sm"
                        >
                            <p class="flex items-center gap-1.5 font-medium">
                                <ShieldCheck class="size-4 text-brand-gold" />
                                Approval needed
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                AiMe wants to run
                                {{ pendingApproval.length }}
                                action{{
                                    pendingApproval.length > 1 ? 's' : ''
                                }}
                                that will change external data:
                            </p>
                            <ul class="mt-2 space-y-1.5">
                                <li
                                    v-for="(c, ci) in pendingApproval"
                                    :key="ci"
                                    class="rounded-lg border border-border bg-background px-2.5 py-1.5"
                                >
                                    <p class="font-mono text-xs font-medium">
                                        {{ c.name }}
                                    </p>
                                    <pre
                                        class="mt-1 max-h-32 overflow-auto text-[11px] break-all whitespace-pre-wrap text-muted-foreground"
                                        >{{
                                            JSON.stringify(c.input, null, 2)
                                        }}</pre
                                    >
                                </li>
                            </ul>
                            <div class="mt-3 flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-brand-gold px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-gold/90"
                                    @click="decideTools(true)"
                                >
                                    Approve &amp; run
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                                    @click="decideTools(false)"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

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
                                {{ streamingTool }}…
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
                                        {{ s.name }}
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
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                                aria-label="Attach files"
                                :disabled="privateOn || !modelIsClaude"
                                :title="
                                    privateOn
                                        ? 'Attachments are off in private chats — files would have to be stored'
                                        : !modelIsClaude
                                          ? 'Attachments work with Claude models only'
                                          : 'Attach images or PDFs'
                                "
                                @click="openFilePicker"
                            >
                                <Paperclip class="size-5" />
                            </button>
                            <button
                                type="button"
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                                :class="
                                    imageMode
                                        ? 'bg-brand-gold/10 text-brand-gold'
                                        : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                                "
                                aria-label="Generate an image"
                                :disabled="privateOn"
                                :title="
                                    privateOn
                                        ? 'Image generation is off in private chats — images would have to be stored'
                                        : imageMode
                                          ? 'Image mode is ON — your message becomes an image prompt. Click to go back to chat.'
                                          : imageEnabled
                                            ? 'Generate an image from your next message'
                                            : 'Image generation isn\'t enabled — click to request it from your admin'
                                "
                                @click="toggleImageMode"
                            >
                                <ImagePlus class="size-5" />
                            </button>
                            <textarea
                                v-model="draft"
                                rows="1"
                                :placeholder="
                                    imageMode
                                        ? 'Describe the image to generate…'
                                        : 'Message AiMe BOT…'
                                "
                                class="max-h-40 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground"
                                @keydown="onKeydown"
                                @paste="onPaste"
                            />
                            <button
                                type="button"
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl transition-colors"
                                :class="
                                    recording
                                        ? 'animate-pulse bg-destructive/10 text-destructive'
                                        : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                                "
                                :aria-label="
                                    recording ? 'Stop recording' : 'Dictate'
                                "
                                :title="
                                    recording
                                        ? 'Stop recording — the audio is transcribed into the message box'
                                        : speechEnabled
                                          ? 'Dictate your message'
                                          : 'Speech isn\'t enabled — click to request it from your admin'
                                "
                                @click="toggleRecording"
                            >
                                <component
                                    :is="transcribing ? Loader2 : Mic"
                                    class="size-5"
                                    :class="transcribing ? 'animate-spin' : ''"
                                />
                            </button>
                            <button
                                type="button"
                                :disabled="
                                    loading ||
                                    (draft.trim().length === 0 &&
                                        pendingFiles.length === 0)
                                "
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
                                aria-label="Send message"
                                @click="send()"
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

        <!-- Share link dialog -->
        <Dialog v-model:open="showShareDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Share this chat</DialogTitle>
                    <DialogDescription>
                        Anyone signed in to the portal with the link can view a
                        read-only copy of this conversation. Turning sharing off
                        invalidates the link.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="shareUrl" class="flex items-center gap-2">
                    <input
                        readonly
                        :value="shareUrl"
                        class="h-9 flex-1 rounded-md border border-input bg-muted/40 px-3 text-xs text-muted-foreground outline-none"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button
                        variant="secondary"
                        size="sm"
                        @click="copyShareLink"
                    >
                        {{ shareCopied ? 'Copied!' : 'Copy' }}
                    </Button>
                </div>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="secondary">Close</Button>
                    </DialogClose>
                    <Button
                        v-if="!shareUrl"
                        class="bg-brand-gold text-white hover:bg-brand-gold/90"
                        :disabled="shareBusy"
                        @click="toggleShare"
                    >
                        Create share link
                    </Button>
                    <Button
                        v-else
                        variant="destructive"
                        :disabled="shareBusy"
                        @click="toggleShare"
                    >
                        Stop sharing
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Locked provider → request API access from the admin -->
        <Dialog
            :open="requestTarget !== null"
            @update:open="(v: boolean) => !v && (requestTarget = null)"
        >
            <DialogContent v-if="requestTarget">
                <DialogHeader>
                    <DialogTitle>
                        {{ requestTarget.provider.name }} isn't enabled yet
                    </DialogTitle>
                    <DialogDescription>
                        Using {{ requestTarget.model.label }} needs a
                        {{ requestTarget.provider.name }} API key on the server,
                        which only an admin can add. Send a request? It lands on
                        the admin's dashboard with your name.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="secondary" @click="requestTarget = null">
                        Cancel
                    </Button>
                    <Button
                        class="bg-brand-gold text-white hover:bg-brand-gold/90"
                        :disabled="requestSending"
                        @click="sendApiRequest"
                    >
                        {{ requestSending ? 'Sending…' : 'Request access' }}
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
