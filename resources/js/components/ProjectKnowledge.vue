<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    project: {
        id: number;
        name: string;
        instructions: string | null;
        memory: string | null;
    };
}>();

const emit = defineEmits<{ (e: 'saved'): void }>();

const confirmingDelete = ref(false);

const form = useForm({
    name: props.project.name,
    instructions: props.project.instructions ?? '',
    memory: props.project.memory ?? '',
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

            <div class="space-y-1.5">
                <Label for="k-memory">Memory</Label>
                <textarea
                    id="k-memory"
                    v-model="form.memory"
                    rows="5"
                    placeholder="Facts and context to remember across chats."
                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                />
                <p class="text-xs text-muted-foreground">
                    Facts the assistant should remember across chats.
                </p>
            </div>

            <Button class="w-full" :disabled="form.processing" @click="save">
                Save changes
            </Button>
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
