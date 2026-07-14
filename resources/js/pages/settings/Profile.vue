<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { Check, Pencil, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    memoryEnabled: boolean;
    memories: { id: number; content: string }[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

// --- Assistant memory ---------------------------------------------------------

function toggleMemory() {
    router.patch(
        '/settings/memory',
        { enabled: !props.memoryEnabled },
        { preserveScroll: true },
    );
}

const editingMemoryId = ref<number | null>(null);
const editingText = ref('');

function startEditMemory(m: { id: number; content: string }) {
    editingMemoryId.value = m.id;
    editingText.value = m.content;
}

function saveMemory() {
    if (editingMemoryId.value === null || !editingText.value.trim()) {
        return;
    }

    router.patch(
        `/settings/memories/${editingMemoryId.value}`,
        { content: editingText.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => (editingMemoryId.value = null),
        },
    );
}

function deleteMemory(id: number) {
    router.delete(`/settings/memories/${id}`, { preserveScroll: true });
}

function clearMemories() {
    if (confirm('Forget everything AiMe has learned about you?')) {
        router.delete('/settings/memories', { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Your account and how AiMe works for you"
        />

        <SettingsSection label="Account">
            <Form
                v-bind="ProfileController.update.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        class="mt-1 block w-full"
                        name="name"
                        :default-value="user.name"
                        required
                        autocomplete="name"
                        placeholder="Full name"
                    />
                    <InputError class="mt-2" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        class="mt-1 block w-full"
                        name="email"
                        :default-value="user.email"
                        required
                        autocomplete="username"
                        placeholder="Email address"
                    />
                    <InputError class="mt-2" :message="errors.email" />
                </div>

                <div
                    v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                >
                    <p class="-mt-4 text-sm text-muted-foreground">
                        Your email address is unverified.
                        <Link
                            :href="send()"
                            as="button"
                            class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                        >
                            Click here to re-send the verification email.
                        </Link>
                    </p>

                    <div
                        v-if="page.props.status === 'verification-link-sent'"
                        class="mt-2 text-sm font-medium text-green-600"
                    >
                        A new verification link has been sent to your email
                        address.
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        :disabled="processing"
                        data-test="update-profile-button"
                        >Save</Button
                    >
                </div>
            </Form>
        </SettingsSection>

        <SettingsSection label="Chat preferences">
            <Form
                action="/settings/chat-preferences"
                method="patch"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="chat-preferences"
                        >Standing instructions for AiMe BOT</Label
                    >
                    <textarea
                        id="chat-preferences"
                        name="chat_preferences"
                        rows="4"
                        maxlength="2000"
                        placeholder="e.g. Always answer in Tagalog. Keep answers short. Use metric units."
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                        :value="user.chat_preferences ?? ''"
                    />
                    <p class="text-xs text-muted-foreground">
                        Applied to every conversation. These adjust tone and
                        format only — they can't override the assistant's safety
                        rules. Leave empty to clear.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors.chat_preferences"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="processing">Save preferences</Button>
                </div>
            </Form>
        </SettingsSection>

        <SettingsSection label="Assistant memory" variant="rows">
            <div class="flex items-center justify-between gap-4 px-4 py-3">
                <p class="text-sm text-muted-foreground">
                    {{
                        memoryEnabled
                            ? 'Automatic memory is on — AiMe distills durable facts (role, projects, preferences) from your conversations.'
                            : 'Automatic memory is off — nothing new is learned and saved memories are not used.'
                    }}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="shrink-0"
                    @click="toggleMemory"
                >
                    {{ memoryEnabled ? 'Turn off' : 'Turn on' }}
                </Button>
            </div>

            <p
                v-if="memories.length === 0"
                class="px-4 py-6 text-center text-sm text-muted-foreground"
            >
                Nothing remembered yet — memories appear here as you chat.
            </p>

            <div
                v-for="m in memories"
                :key="m.id"
                class="flex items-center gap-2 px-4 py-2.5"
            >
                <template v-if="editingMemoryId === m.id">
                    <Input
                        v-model="editingText"
                        class="h-8 flex-1 text-sm"
                        maxlength="500"
                        @keyup.enter="saveMemory"
                        @keyup.esc="editingMemoryId = null"
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Save memory"
                        @click="saveMemory"
                    >
                        <Check class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Cancel"
                        @click="editingMemoryId = null"
                    >
                        <X class="size-4" />
                    </Button>
                </template>
                <template v-else>
                    <span class="flex-1 text-sm">{{ m.content }}</span>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        aria-label="Edit memory"
                        @click="startEditMemory(m)"
                    >
                        <Pencil class="size-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-destructive"
                        aria-label="Delete memory"
                        @click="deleteMemory(m.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </template>
            </div>

            <div v-if="memories.length > 0" class="px-4 py-3">
                <Button
                    variant="outline"
                    size="sm"
                    class="text-destructive"
                    @click="clearMemories"
                >
                    Forget everything
                </Button>
            </div>
        </SettingsSection>

        <SettingsSection label="Danger zone" variant="plain">
            <DeleteUser />
        </SettingsSection>
    </div>
</template>
