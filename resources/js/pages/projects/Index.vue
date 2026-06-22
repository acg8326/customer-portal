<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FolderOpen, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: '/projects' }],
    },
});

defineProps<{
    projects: { id: number; name: string; updated_at: string | null }[];
}>();

const open = ref(false);
const form = useForm({ name: '' });
const confirmId = ref<number | null>(null);

function create() {
    form.post('/projects', {
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function confirmDelete(id: number) {
    if (confirmId.value !== id) {
        confirmId.value = id;

        return;
    }

    router.delete(`/projects/${id}`, { preserveScroll: true });
    confirmId.value = null;
}
</script>

<template>
    <Head title="Projects" />

    <div class="mx-auto w-full max-w-5xl p-6">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Projects</h1>
                <p class="text-sm text-muted-foreground">
                    Workspaces with their own instructions, memory, and chats.
                </p>
            </div>

            <Dialog v-model:open="open">
                <DialogTrigger as-child>
                    <Button>
                        <Plus class="size-4" />
                        New project
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New project</DialogTitle>
                    </DialogHeader>
                    <form class="space-y-4" @submit.prevent="create">
                        <div class="space-y-2">
                            <Label for="name">Project name</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                placeholder="e.g. RMA Evaluation AI Agent"
                                autofocus
                            />
                            <p
                                v-if="form.errors.name"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>
                        <DialogFooter>
                            <Button
                                type="submit"
                                :disabled="
                                    form.processing || form.name.trim() === ''
                                "
                            >
                                Create project
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>

        <!-- Empty state -->
        <div
            v-if="projects.length === 0"
            class="flex flex-col items-center justify-center rounded-2xl border border-dashed py-20 text-center"
        >
            <div
                class="mb-4 flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground"
            >
                <FolderOpen class="size-6" />
            </div>
            <p class="font-medium">No projects yet</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Create a project to give the assistant lasting instructions and
                memory for a specific job.
            </p>
        </div>

        <!-- Grid -->
        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="p in projects" :key="p.id" class="group relative">
                <Link
                    :href="`/projects/${p.id}`"
                    class="block rounded-xl border bg-card p-5 transition-colors hover:border-ring hover:bg-accent/40"
                >
                    <div
                        class="mb-3 flex size-10 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-400 to-indigo-500 text-white"
                    >
                        <FolderOpen class="size-5" />
                    </div>
                    <p class="truncate pr-6 font-medium">{{ p.name }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Open workspace
                    </p>
                </Link>

                <button
                    type="button"
                    class="absolute top-3 right-3 rounded-md p-1.5 transition hover:bg-destructive/10"
                    :class="
                        confirmId === p.id
                            ? 'text-destructive opacity-100'
                            : 'text-muted-foreground opacity-0 group-hover:opacity-100'
                    "
                    :title="
                        confirmId === p.id
                            ? 'Click again to delete'
                            : 'Delete project'
                    "
                    @click="confirmDelete(p.id)"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
