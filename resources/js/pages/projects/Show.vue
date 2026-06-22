<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FolderOpen, Settings2 } from '@lucide/vue';
import { ref } from 'vue';
import ChatPanel from '@/components/ChatPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: '/projects' }],
    },
});

const props = defineProps<{
    project: {
        id: number;
        name: string;
        instructions: string | null;
        memory: string | null;
    };
    models: { value: string; label: string }[];
    defaultModel: string;
    conversations: { id: number; title: string }[];
}>();

const settingsOpen = ref(false);
const confirmingDelete = ref(false);

const form = useForm({
    name: props.project.name,
    instructions: props.project.instructions ?? '',
    memory: props.project.memory ?? '',
});

function save() {
    form.patch(`/projects/${props.project.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            settingsOpen.value = false;
        },
    });
}

function destroy() {
    if (!confirmingDelete.value) {
        confirmingDelete.value = true;

        return;
    }

    router.delete(`/projects/${props.project.id}`);
}
</script>

<template>
    <Head :title="project.name" />

    <div class="mx-auto h-[calc(100svh-4rem)] w-full max-w-5xl p-4">
        <ChatPanel
            :models="models"
            :default-model="defaultModel"
            :conversations="conversations"
            :project-id="project.id"
        >
            <template #brand>
                <Link
                    href="/projects"
                    class="rounded p-1 text-muted-foreground hover:bg-accent"
                    aria-label="Back to projects"
                >
                    <ArrowLeft class="size-4" />
                </Link>
                <div
                    class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-indigo-500 text-white shadow-sm"
                >
                    <FolderOpen class="size-4" />
                </div>
                <div class="min-w-0 leading-tight">
                    <p class="truncate text-sm font-semibold">
                        {{ project.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">Project</p>
                </div>
                <button
                    type="button"
                    class="ml-1 rounded p-1 text-muted-foreground hover:bg-accent"
                    aria-label="Project settings"
                    @click="settingsOpen = true"
                >
                    <Settings2 class="size-4" />
                </button>
            </template>

            <template #empty>
                Ask anything about {{ project.name }}. I'll use this project's
                instructions and memory.
            </template>
        </ChatPanel>
    </div>

    <!-- Project settings -->
    <Dialog v-model:open="settingsOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Project settings</DialogTitle>
                <DialogDescription>
                    Instructions and memory are added to every chat in this
                    project.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="save">
                <div class="space-y-2">
                    <Label for="p-name">Name</Label>
                    <Input id="p-name" v-model="form.name" />
                    <p v-if="form.errors.name" class="text-sm text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="p-instructions">Instructions</Label>
                    <textarea
                        id="p-instructions"
                        v-model="form.instructions"
                        rows="4"
                        placeholder="How should the assistant behave in this project?"
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="p-memory">Memory</Label>
                    <textarea
                        id="p-memory"
                        v-model="form.memory"
                        rows="4"
                        placeholder="Facts and context to remember across chats."
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                </div>

                <DialogFooter class="flex items-center justify-between gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        @click="destroy"
                    >
                        {{
                            confirmingDelete
                                ? 'Click again to delete'
                                : 'Delete project'
                        }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
