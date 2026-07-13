<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { FileText, Loader2, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    project: {
        id: number;
        name: string;
        instructions: string | null;
    };
    files?: { id: number; name: string; size: number }[];
    fileLimits?: { maxFiles: number; mimes: string };
}>();

const emit = defineEmits<{ (e: 'saved'): void }>();

const confirmingDelete = ref(false);

const form = useForm({
    name: props.project.name,
    instructions: props.project.instructions ?? '',
});

function save() {
    form.patch(`/projects/${props.project.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}

function destroy() {
    if (!confirmingDelete.value) {
        confirmingDelete.value = true;

        return;
    }

    router.delete(`/projects/${props.project.id}`);
}

// --- Knowledge-base files -------------------------------------------------------

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const fileError = ref<string | null>(null);

function acceptAttr(): string {
    return (props.fileLimits?.mimes ?? 'docx,xlsx,csv,txt,md')
        .split(',')
        .map((ext) => `.${ext.trim()}`)
        .join(',');
}

function pickFiles() {
    fileError.value = null;
    fileInput.value?.click();
}

function uploadFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';

    if (files.length === 0) {
        return;
    }

    uploading.value = true;

    router.post(
        `/projects/${props.project.id}/files`,
        { files },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) =>
                (fileError.value =
                    Object.values(errors)[0] ?? 'Upload failed.'),
            onFinish: () => (uploading.value = false),
        },
    );
}

function removeFile(id: number) {
    router.delete(`/projects/${props.project.id}/files/${id}`, {
        preserveScroll: true,
    });
}

function sizeLabel(bytes: number): string {
    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex-1 space-y-5 overflow-y-auto p-4">
            <div>
                <h3 class="text-sm font-semibold">Project knowledge</h3>
                <p class="text-xs text-muted-foreground">
                    Added to every chat in this project.
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="k-name">Name</Label>
                <Input id="k-name" v-model="form.name" />
                <p v-if="form.errors.name" class="text-xs text-destructive">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="k-instructions">Instructions</Label>
                <textarea
                    id="k-instructions"
                    v-model="form.instructions"
                    rows="5"
                    placeholder="How should the assistant behave in this project?"
                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                />
                <p class="text-xs text-muted-foreground">
                    Tells the assistant how to behave here.
                </p>
            </div>

            <Button class="w-full" :disabled="form.processing" @click="save">
                Save changes
            </Button>

            <div class="space-y-1.5 border-t pt-4">
                <div class="flex items-center justify-between">
                    <Label>Files</Label>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50"
                        :disabled="
                            uploading ||
                            (files?.length ?? 0) >= (fileLimits?.maxFiles ?? 10)
                        "
                        @click="pickFiles"
                    >
                        <component
                            :is="uploading ? Loader2 : Plus"
                            class="size-3.5"
                            :class="uploading ? 'animate-spin' : ''"
                        />
                        Add
                    </button>
                </div>
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    multiple
                    :accept="acceptAttr()"
                    @change="uploadFiles"
                />
                <p class="text-xs text-muted-foreground">
                    Documents ({{
                        fileLimits?.mimes ?? 'docx,xlsx,csv,txt,md'
                    }}) the assistant can use in every chat here — max
                    {{ fileLimits?.maxFiles ?? 10 }}.
                </p>
                <p v-if="fileError" class="text-xs text-destructive">
                    {{ fileError }}
                </p>

                <ul v-if="files?.length" class="space-y-1 pt-1">
                    <li
                        v-for="f in files"
                        :key="f.id"
                        class="group flex items-center gap-2 rounded-md border px-2 py-1.5"
                    >
                        <FileText
                            class="size-3.5 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 flex-1 truncate text-xs">{{
                            f.name
                        }}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{
                            sizeLabel(f.size)
                        }}</span>
                        <button
                            type="button"
                            class="shrink-0 rounded p-0.5 text-muted-foreground opacity-0 transition group-hover:opacity-100 hover:text-destructive"
                            aria-label="Remove file"
                            @click="removeFile(f.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <div class="border-t p-4">
            <Button
                variant="ghost"
                class="w-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                @click="destroy"
            >
                {{
                    confirmingDelete
                        ? 'Click again to delete project'
                        : 'Delete project'
                }}
            </Button>
        </div>
    </div>
</template>
