<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ChatPanel from '@/components/ChatPanel.vue';
import { chat } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chat',
                href: chat(),
            },
        ],
    },
});

defineProps<{
    models: { value: string; label: string }[];
    defaultModel: string;
    conversations: { id: number; title: string }[];
}>();
</script>

<template>
    <Head title="Chat" />

    <div class="mx-auto h-[calc(100svh-4rem)] w-full max-w-5xl p-4">
        <ChatPanel
            :models="models"
            :default-model="defaultModel"
            :conversations="conversations"
        />
    </div>
</template>
