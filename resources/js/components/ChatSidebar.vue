<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';

defineProps<{
    conversations: { id: number; title: string }[];
    activeId: number | null;
}>();

const emit = defineEmits<{
    (e: 'select', id: number): void;
    (e: 'remove', id: number): void;
    (e: 'new'): void;
}>();
</script>

<template>
    <div class="flex h-full flex-col bg-muted/30">
        <div class="p-3">
            <button
                type="button"
                class="flex w-full items-center justify-center gap-2 rounded-lg border bg-background px-3 py-2 text-sm font-medium transition-colors hover:bg-accent"
                @click="emit('new')"
            >
                <Plus class="size-4" />
                New chat
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-2 pb-2">
            <p
                v-if="conversations.length === 0"
                class="px-3 py-6 text-center text-xs text-muted-foreground"
            >
                No chats yet.
            </p>

            <button
                v-for="c in conversations"
                :key="c.id"
                type="button"
                class="group flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
                :class="
                    c.id === activeId
                        ? 'bg-accent font-medium'
                        : 'text-foreground/80'
                "
                @click="emit('select', c.id)"
            >
                <span class="flex-1 truncate">{{ c.title }}</span>
                <span
                    role="button"
                    aria-label="Delete chat"
                    class="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition group-hover:opacity-100 hover:bg-destructive/10 hover:text-destructive"
                    @click.stop="emit('remove', c.id)"
                >
                    <Trash2 class="size-3.5" />
                </span>
            </button>
        </div>
    </div>
</template>
