<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Skill = {
    id: number;
    name: string;
    icon: string | null;
    description: string | null;
    instructions: string;
};

type Template = {
    name: string;
    icon: string;
    description: string;
    instructions: string;
};

const props = defineProps<{
    skills: Skill[];
    library: Template[];
}>();

const existingNames = computed(
    () => new Set(props.skills.map((s) => s.name.trim().toLowerCase())),
);

function alreadyAdded(name: string): boolean {
    return existingNames.value.has(name.trim().toLowerCase());
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Skills', href: '/settings/skills' }],
    },
});

const dialogOpen = ref(false);
const editingId = ref<number | null>(null);
const confirmDeleteId = ref<number | null>(null);

const form = useForm({
    name: '',
    icon: '',
    description: '',
    instructions: '',
});

const importOpen = ref(false);
const importForm = useForm<{ file: File | null; content: string }>({
    file: null,
    content: '',
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(skill: Skill) {
    editingId.value = skill.id;
    form.name = skill.name;
    form.icon = skill.icon ?? '';
    form.description = skill.description ?? '';
    form.instructions = skill.instructions;
    form.clearErrors();
    dialogOpen.value = true;
}

function save() {
    const onSuccess = () => {
        dialogOpen.value = false;
        form.reset();
    };

    if (editingId.value != null) {
        form.patch(`/settings/skills/${editingId.value}`, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        form.post('/settings/skills', { preserveScroll: true, onSuccess });
    }
}

function addFromLibrary(t: Template) {
    if (alreadyAdded(t.name)) {
        return;
    }

    router.post('/settings/skills', { ...t }, { preserveScroll: true });
}

function destroy(id: number) {
    router.delete(`/settings/skills/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            confirmDeleteId.value = null;
        },
    });
}

function onImportFile(e: Event) {
    const input = e.target as HTMLInputElement;
    importForm.file = input.files?.[0] ?? null;
}

function runImport() {
    importForm.post('/settings/skills/import', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            importOpen.value = false;
            importForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Skills" />

    <h1 class="sr-only">Skills</h1>

    <div class="flex flex-col space-y-8">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Skills"
                description="Reusable instruction presets you can apply in any chat."
            />
            <div class="flex shrink-0 gap-2">
                <Button variant="outline" size="sm" @click="importOpen = true">
                    <Upload class="size-4" />
                    Import
                </Button>
                <Button size="sm" @click="openCreate">
                    <Plus class="size-4" />
                    New skill
                </Button>
            </div>
        </div>

        <!-- Your skills -->
        <section class="space-y-3">
            <div
                v-if="skills.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No skills yet. Create one, import a SKILL.md, or add from the
                starter library below.
            </div>

            <div
                v-for="skill in skills"
                :key="skill.id"
                class="flex items-start gap-3 rounded-lg border p-4"
            >
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-lg"
                >
                    {{ skill.icon || '✨' }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ skill.name }}</p>
                    <p
                        v-if="skill.description"
                        class="text-sm text-muted-foreground"
                    >
                        {{ skill.description }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <template v-if="confirmDeleteId === skill.id">
                        <Button
                            variant="destructive"
                            size="sm"
                            @click="destroy(skill.id)"
                        >
                            Delete
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="confirmDeleteId = null"
                        >
                            Cancel
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            aria-label="Edit skill"
                            @click="openEdit(skill)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8 text-muted-foreground"
                            aria-label="Delete skill"
                            title="Delete skill"
                            @click="confirmDeleteId = skill.id"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </template>
                </div>
            </div>
        </section>

        <!-- Starter library -->
        <section class="space-y-3">
            <Heading
                variant="small"
                title="Starter library"
                description="Add a ready-made skill to your account, then customise it."
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="t in library"
                    :key="t.name"
                    class="flex items-start gap-3 rounded-lg border p-4"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-lg"
                    >
                        {{ t.icon }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ t.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ t.description }}
                        </p>
                    </div>
                    <Button
                        v-if="alreadyAdded(t.name)"
                        variant="ghost"
                        size="sm"
                        class="shrink-0 text-muted-foreground"
                        disabled
                    >
                        Added
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        @click="addFromLibrary(t)"
                    >
                        <Plus class="size-4" />
                        Add
                    </Button>
                </div>
            </div>
        </section>
    </div>

    <!-- Create / edit dialog -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    editingId != null ? 'Edit skill' : 'New skill'
                }}</DialogTitle>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="save">
                <div class="flex gap-3">
                    <div class="w-20 space-y-2">
                        <Label for="skill-icon">Icon</Label>
                        <Input
                            id="skill-icon"
                            v-model="form.icon"
                            placeholder="✨"
                            maxlength="8"
                        />
                    </div>
                    <div class="flex-1 space-y-2">
                        <Label for="skill-name">Name</Label>
                        <Input
                            id="skill-name"
                            v-model="form.name"
                            placeholder="e.g. RMA evaluator"
                            autofocus
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                </div>
                <div class="space-y-2">
                    <Label for="skill-desc">Description</Label>
                    <Input
                        id="skill-desc"
                        v-model="form.description"
                        placeholder="Short summary of what this skill does"
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <div class="space-y-2">
                    <Label for="skill-instr">Instructions</Label>
                    <textarea
                        id="skill-instr"
                        v-model="form.instructions"
                        rows="7"
                        placeholder="How the assistant should behave when this skill is active…"
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                    <InputError :message="form.errors.instructions" />
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.name.trim() === '' ||
                            form.instructions.trim() === ''
                        "
                    >
                        {{
                            editingId != null ? 'Save changes' : 'Create skill'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Import dialog -->
    <Dialog v-model:open="importOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Import a skill</DialogTitle>
            </DialogHeader>
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Upload a <code>SKILL.md</code> file, or paste its contents.
                    Front-matter <code>name</code> /
                    <code>description</code> are used if present.
                </p>
                <div class="space-y-2">
                    <Label for="skill-file">SKILL.md file</Label>
                    <input
                        id="skill-file"
                        type="file"
                        accept=".md,.markdown,.txt"
                        class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm"
                        @change="onImportFile"
                    />
                    <InputError :message="importForm.errors.file" />
                </div>
                <div class="space-y-2">
                    <Label for="skill-paste">…or paste contents</Label>
                    <textarea
                        id="skill-paste"
                        v-model="importForm.content"
                        rows="6"
                        placeholder="---&#10;name: My Skill&#10;description: …&#10;---&#10;Instructions here…"
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                    <InputError :message="importForm.errors.content" />
                </div>
                <DialogFooter>
                    <Button
                        :disabled="
                            importForm.processing ||
                            (!importForm.file &&
                                importForm.content.trim() === '')
                        "
                        @click="runImport"
                    >
                        Import skill
                    </Button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>
