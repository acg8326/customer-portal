<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Eye, Paperclip, Sparkles } from '@lucide/vue';
import MarkdownContent from '@/components/MarkdownContent.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Shared chat', href: '#' }],
        fullWidth: true,
    },
});

defineProps<{
    title: string;
    owner: string | null;
    messages: {
        id: number;
        role: string;
        content: string;
        attachments: string[];
    }[];
}>();
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto w-full max-w-3xl p-4">
        <div class="mb-6 rounded-xl border bg-card p-4">
            <h1 class="text-lg font-semibold tracking-tight">{{ title }}</h1>
            <p
                class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <Eye class="size-3.5" />
                Read-only shared conversation<template v-if="owner">
                    · shared by {{ owner }}</template
                >
            </p>
        </div>

        <div class="space-y-4">
            <div
                v-for="m in messages"
                :key="m.id"
                class="flex gap-3"
                :class="m.role === 'user' ? 'justify-end' : ''"
            >
                <div
                    v-if="m.role === 'assistant'"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white"
                >
                    <Sparkles class="size-4" />
                </div>
                <div
                    class="max-w-[85%] rounded-2xl px-4 py-3 text-sm"
                    :class="
                        m.role === 'user'
                            ? 'bg-brand-gold/90 text-brand-navy'
                            : 'border bg-card'
                    "
                >
                    <ul
                        v-if="m.attachments.length"
                        class="mb-2 space-y-0.5 text-xs opacity-80"
                    >
                        <li
                            v-for="(name, i) in m.attachments"
                            :key="i"
                            class="flex items-center gap-1"
                        >
                            <Paperclip class="size-3" /> {{ name }}
                        </li>
                    </ul>
                    <MarkdownContent
                        v-if="m.role === 'assistant'"
                        :content="m.content"
                    />
                    <template v-else>{{ m.content }}</template>
                </div>
            </div>
        </div>
    </div>
</template>
