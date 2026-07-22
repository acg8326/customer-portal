<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
        <section class="space-y-4">
            <h2 class="text-sm font-semibold">Set up Claude Code</h2>
            <ol
                class="space-y-3 text-sm text-muted-foreground [counter-reset:step]"
            >
                <li>
                    <span class="font-medium text-foreground">1.</span> Install
                    the <span class="font-medium">Claude Code</span> extension
                    in VS Code (or the CLI / JetBrains).
                </li>
                <li>
                    <span class="font-medium text-foreground">2.</span> Generate
                    a token below and copy it — you'll only see it once.
                </li>
                <li>
                    <span class="font-medium text-foreground">3.</span> Point
                    Claude Code at AiMe with these environment variables:
                </li>
            </ol>

            <div class="relative">
                <pre
                    class="overflow-x-auto rounded-lg border bg-muted/40 p-3 text-xs"
                ><code>{{ envSnippet }}</code></pre>
                <button
                    type="button"
                    class="absolute top-2 right-2 inline-flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-xs text-muted-foreground hover:text-foreground"
                    @click="copy(envSnippet, 'env')"
                >
                    <component
                        :is="copied === 'env' ? Check : Copy"
                        class="size-3"
                    />
                    {{ copied === 'env' ? 'Copied' : 'Copy' }}
                </button>
            </div>

            <p class="text-xs text-muted-foreground">
                <template v-if="assignedModel">
                    Your account is pinned to
                    <span class="font-medium text-foreground">{{
                        assignedModel
                    }}</span>
                    — requests run on that model whatever Claude Code is set to.
                </template>
                <template v-else>
                    Tip: set <code>ANTHROPIC_MODEL</code> in Claude Code so its
                    built-in "sonnet/opus/haiku" names map to a full model id.
                </template>
            </p>
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
