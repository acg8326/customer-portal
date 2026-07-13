<script setup lang="ts">
import { Plus, Star, Trash2 } from '@lucide/vue';
import { computed } from 'vue';

type SidebarChat = { id: number; title: string; starred?: boolean };

const props = defineProps<{
    conversations: SidebarChat[];
    activeId: number | null;
}>();

const emit = defineEmits<{
    (e: 'select', id: number): void;
    (e: 'remove', id: number): void;
    (e: 'star', id: number): void;
    (e: 'new'): void;
}>();

// claude.ai-style grouping: a "Starred" section (only when something is
// starred) above "Recents". Order within each group comes from the parent
// (most recent activity first).
const groups = computed(() => {
    const starred = props.conversations.filter((c) => c.starred);
    const recents = props.conversations.filter((c) => !c.starred);

    return [
        { label: 'Starred', items: starred },
        { label: 'Recents', items: recents },
    ].filter((g) => g.items.length > 0);
});
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

            <template v-for="group in groups" :key="group.label">
                <p
                    class="px-3 pt-3 pb-1 text-xs font-medium text-muted-foreground"
                >
                    {{ group.label }}
                </p>
                <button
                    v-for="c in group.items"
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
                        :aria-label="c.starred ? 'Unstar chat' : 'Star chat'"
                        class="shrink-0 rounded p-1 transition hover:bg-accent-foreground/10"
                        :class="
                            c.starred
                                ? 'text-brand-gold'
                                : 'text-muted-foreground opacity-0 group-hover:opacity-100'
                        "
                        @click.stop="emit('star', c.id)"
                    >
                        <Star
                            class="size-3.5"
                            :fill="c.starred ? 'currentColor' : 'none'"
                        />
                    </span>
                    <span
                        role="button"
                        aria-label="Delete chat"
                        class="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition group-hover:opacity-100 hover:bg-destructive/10 hover:text-destructive"
                        @click.stop="emit('remove', c.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </span>
                </button>
            </template>
        </div>
    </div>
</template>
