<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FolderOpen, MessageSquare, Search } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

type SearchResult = {
    id: number;
    title: string;
    project_id: number | null;
    snippet: string | null;
};

const open = defineModel<boolean>('open', { default: false });

const query = ref('');
const results = ref<SearchResult[]>([]);
const loading = ref(false);
const searched = ref(false);
const inputEl = ref<HTMLInputElement | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

watch(open, async (isOpen) => {
    if (isOpen) {
        query.value = '';
        results.value = [];
        searched.value = false;
        await nextTick();
        inputEl.value?.focus();
    }
});

function onInput() {
    if (timer) {
        clearTimeout(timer);
    }

    const q = query.value.trim();

    if (q === '') {
        results.value = [];
        searched.value = false;
        loading.value = false;

        return;
    }

    loading.value = true;
    timer = setTimeout(() => runSearch(q), 250);
}

async function runSearch(q: string) {
    try {
        const res = await fetch(`/chat/search?q=${encodeURIComponent(q)}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json();
        results.value = Array.isArray(data.results) ? data.results : [];
    } catch {
        results.value = [];
    } finally {
        loading.value = false;
        searched.value = true;
    }
}

function openResult(r: SearchResult) {
    const target =
        r.project_id != null
            ? `/projects/${r.project_id}?c=${r.id}`
            : `/chat?c=${r.id}`;
    open.value = false;
    router.visit(target);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-xl">
            <DialogTitle class="sr-only">Search chats</DialogTitle>

            <div class="flex items-center gap-2 border-b px-4">
                <Search class="size-4 shrink-0 text-muted-foreground" />
                <input
                    ref="inputEl"
                    v-model="query"
                    type="text"
                    placeholder="Search your chats…"
                    class="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    @input="onInput"
                />
                <Spinner v-if="loading" class="size-4 text-muted-foreground" />
            </div>

            <div class="max-h-[60vh] overflow-y-auto p-2">
                <button
                    v-for="r in results"
                    :key="r.id"
                    type="button"
                    class="flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-accent"
                    @click="openResult(r)"
                >
                    <div
                        class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                    >
                        <component
                            :is="
                                r.project_id != null
                                    ? FolderOpen
                                    : MessageSquare
                            "
                            class="size-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ r.title }}
                        </p>
                        <p
                            v-if="r.snippet"
                            class="mt-0.5 line-clamp-2 text-xs text-muted-foreground"
                        >
                            {{ r.snippet }}
                        </p>
                    </div>
                </button>

                <p
                    v-if="searched && !loading && results.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No chats match “{{ query.trim() }}”.
                </p>

                <p
                    v-else-if="!searched && !loading"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    Type to search your chats by title or message.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
