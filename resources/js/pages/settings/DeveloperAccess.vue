<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Kbd from '@/components/Kbd.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Token = {
    id: number;
    name: string;
    last_four: string | null;
    last_used_at: string | null;
    created_at: string | null;
};

const props = defineProps<{
    baseUrl: string;
    assignedModel: string | null;
    tokens: Token[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Developer access', href: '/settings/developer-access' },
        ],
    },
});

const page = usePage();

// Numbered circle badge for each setup step.
const stepClass =
    'flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-gold/15 text-xs font-semibold text-brand-gold';

// The plaintext token is flashed once, right after creation.
const freshToken = computed(
    () =>
        (page.props.flash as { gatewayToken?: string } | undefined)
            ?.gatewayToken ?? null,
);

const form = useForm({ name: '' });

function createToken() {
    form.post('/settings/developer-access/tokens', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function revoke(id: number) {
    router.delete(`/settings/developer-access/tokens/${id}`, {
        preserveScroll: true,
    });
}

// --- Copy helpers ----------------------------------------------------------------

const copied = ref<string | null>(null);

function copy(text: string, key: string) {
    navigator.clipboard?.writeText(text).then(() => {
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 1500);
    });
}

// The exact block to paste into VS Code's user settings.json. Uses the real
// token right after it's generated, otherwise a clearly-fake placeholder.
const settingsSnippet = computed(
    () => `{
  "claudeCode.environmentVariables": [
    { "name": "ANTHROPIC_BASE_URL", "value": "${props.baseUrl}" },
    { "name": "ANTHROPIC_AUTH_TOKEN", "value": "${freshToken.value ?? '<your-token>'}" },
    { "name": "ANTHROPIC_LOG", "value": "debug" }
  ],
  "claudeCode.preferredLocation": "panel"
}`,
);

// Plain env-var form for the CLI / JetBrains (no VS Code settings file).
const envSnippet = computed(
    () =>
        `ANTHROPIC_BASE_URL=${props.baseUrl}\nANTHROPIC_AUTH_TOKEN=${freshToken.value ?? '<your-token>'}`,
);
</script>

<template>
    <Head title="Developer access" />

    <h1 class="sr-only">Developer access</h1>

    <div class="flex flex-col space-y-8">
        <Heading
            title="Developer access"
            description="Use AiMe as the backend for Claude Code (or any Anthropic client). Your requests run on the model your administrator assigned you and count against your token budget."
        />

        <!-- Setup steps -->
        <section class="space-y-5">
            <div>
                <h2 class="text-sm font-semibold">
                    Set up Claude Code in VS Code
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    A one-time setup. No prior experience needed — follow each
                    step in order.
                    <span class="text-muted-foreground/80"
                        >(On macOS use <Kbd>⌘</Kbd> in place of
                        <Kbd>Ctrl</Kbd>.)</span
                    >
                </p>
            </div>

            <ol class="space-y-5 text-sm">
                <!-- 1 -->
                <li class="flex gap-3">
                    <span :class="stepClass">1</span>
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-foreground">
                            Install the Claude Code extension
                        </p>
                        <p class="text-muted-foreground">
                            In VS Code open the Extensions panel
                            (<Kbd>Ctrl</Kbd>+<Kbd>Shift</Kbd>+<Kbd>X</Kbd>),
                            search for
                            <span class="font-medium">Claude Code</span>, and
                            click <span class="font-medium">Install</span>.
                        </p>
                    </div>
                </li>

                <!-- 2 -->
                <li class="flex gap-3">
                    <span :class="stepClass">2</span>
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-foreground">
                            Generate your token
                        </p>
                        <p class="text-muted-foreground">
                            Scroll to
                            <span class="font-medium">Your tokens</span>
                            below, name it (e.g. “VS Code”), and click
                            <span class="font-medium">Generate token</span>.
                            Copy it now — it's shown only once.
                        </p>
                    </div>
                </li>

                <!-- 3 -->
                <li class="flex gap-3">
                    <span :class="stepClass">3</span>
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-foreground">
                            Open your VS Code settings file
                        </p>
                        <p class="text-muted-foreground">
                            Press
                            <Kbd>Ctrl</Kbd>+<Kbd>Shift</Kbd>+<Kbd>P</Kbd> to
                            open the command palette, type
                            <span class="font-medium"
                                >Preferences: Open User Settings (JSON)</span
                            >, and press <Kbd>Enter</Kbd>.
                        </p>
                    </div>
                </li>

                <!-- 4 -->
                <li class="flex gap-3">
                    <span :class="stepClass">4</span>
                    <div class="min-w-0 space-y-2">
                        <p class="font-medium text-foreground">
                            Paste this configuration
                        </p>
                        <p class="text-muted-foreground">
                            Replace everything in that file with the block
                            below, then swap
                            <code class="rounded bg-muted px-1 py-0.5 text-xs"
                                >&lt;your-token&gt;</code
                            >
                            for the token you just copied. Save with
                            <Kbd>Ctrl</Kbd>+<Kbd>S</Kbd>.
                        </p>
                        <div class="relative">
                            <pre
                                class="overflow-x-auto rounded-lg border bg-muted/40 p-3 text-xs"
                            ><code>{{ settingsSnippet }}</code></pre>
                            <button
                                type="button"
                                class="absolute top-2 right-2 inline-flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-xs text-muted-foreground hover:text-foreground"
                                @click="copy(settingsSnippet, 'settings')"
                            >
                                <component
                                    :is="copied === 'settings' ? Check : Copy"
                                    class="size-3"
                                />
                                {{ copied === 'settings' ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Already have settings you want to keep? Instead of
                            replacing, just add the two
                            <code class="rounded bg-muted px-1 py-0.5"
                                >claudeCode.*</code
                            >
                            keys inside your existing outer
                            <code class="rounded bg-muted px-1 py-0.5">{ }</code
                            >.
                        </p>
                    </div>
                </li>

                <!-- 5 -->
                <li class="flex gap-3">
                    <span :class="stepClass">5</span>
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-foreground">
                            Reload VS Code
                        </p>
                        <p class="text-muted-foreground">
                            Press
                            <Kbd>Ctrl</Kbd>+<Kbd>Shift</Kbd>+<Kbd>P</Kbd> again,
                            type
                            <span class="font-medium"
                                >Developer: Reload Window</span
                            >, and press <Kbd>Enter</Kbd>. This loads your new
                            settings.
                        </p>
                    </div>
                </li>

                <!-- 6 -->
                <li class="flex gap-3">
                    <span :class="stepClass">6</span>
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium text-foreground">
                            Open Claude Code and chat
                        </p>
                        <p class="text-muted-foreground">
                            Claude Code opens in the bottom panel. Send a
                            message — if it replies, you're connected to AiMe.
                            You can confirm below: your token's “last used”
                            updates right after your first message.
                        </p>
                    </div>
                </li>
            </ol>

            <p class="text-xs text-muted-foreground">
                <template v-if="assignedModel">
                    Your account is pinned to
                    <span class="font-medium text-foreground">{{
                        assignedModel
                    }}</span>
                    — requests run on that model whatever Claude Code's own
                    model picker says.
                </template>
                <template v-else>
                    Your administrator hasn't pinned a model, so Claude Code's
                    model picker applies.
                </template>
            </p>

            <details class="text-xs text-muted-foreground">
                <summary class="cursor-pointer font-medium text-foreground">
                    Using the Claude Code CLI or JetBrains instead?
                </summary>
                <div class="mt-2 space-y-2">
                    <p>
                        Set these two environment variables in your shell (not
                        the VS Code file above):
                    </p>
                    <div class="relative">
                        <pre
                            class="overflow-x-auto rounded-lg border bg-muted/40 p-3"
                        ><code>{{ envSnippet }}</code></pre>
                        <button
                            type="button"
                            class="absolute top-2 right-2 inline-flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-muted-foreground hover:text-foreground"
                            @click="copy(envSnippet, 'env')"
                        >
                            <component
                                :is="copied === 'env' ? Check : Copy"
                                class="size-3"
                            />
                            {{ copied === 'env' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                </div>
            </details>
        </section>

        <!-- Freshly-created token (shown once) -->
        <section
            v-if="freshToken"
            class="rounded-lg border border-brand-gold/40 bg-brand-gold/5 p-4"
        >
            <p class="mb-2 text-sm font-medium">
                Your new token — copy it now, it won't be shown again:
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="flex-1 overflow-x-auto rounded-md border bg-background px-3 py-2 text-xs break-all"
                    >{{ freshToken }}</code
                >
                <Button
                    variant="outline"
                    size="sm"
                    @click="copy(freshToken, 'token')"
                >
                    <component
                        :is="copied === 'token' ? Check : Copy"
                        class="size-4"
                    />
                    {{ copied === 'token' ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </section>

        <!-- Generate a token -->
        <section class="space-y-3">
            <h2 class="text-sm font-semibold">Your tokens</h2>

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="createToken"
            >
                <div class="min-w-56 flex-1">
                    <Label for="token-name">Name a new token</Label>
                    <Input
                        id="token-name"
                        v-model="form.name"
                        type="text"
                        maxlength="60"
                        placeholder="e.g. Work laptop, VS Code"
                        class="mt-1"
                    />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <Button
                    type="submit"
                    :disabled="form.processing || !form.name.trim()"
                >
                    <KeyRound class="size-4" />
                    Generate token
                </Button>
            </form>

            <!-- Token list -->
            <ul v-if="tokens.length" class="divide-y rounded-lg border">
                <li
                    v-for="t in tokens"
                    :key="t.id"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <KeyRound class="size-4 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ t.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            <span v-if="t.last_four">…{{ t.last_four }} · </span
                            >created {{ t.created_at }}
                            <template v-if="t.last_used_at"
                                >· last used {{ t.last_used_at }}</template
                            >
                            <template v-else>· never used</template>
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        title="Revoke this token"
                        @click="revoke(t.id)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                No tokens yet — generate one above to connect Claude Code.
            </p>
        </section>
    </div>
</template>
