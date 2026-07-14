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
        fullWidth: true,
    },
});

defineProps<{
    providers: {
        key: string;
        name: string;
        available: boolean;
        blurb: string;
        models: { value: string; label: string; hint: string }[];
    }[];
    defaultModel: string;
    conversations: { id: number; title: string }[];
    uploads: {
        enabled: boolean;
        maxFiles: number;
        maxSizeKb: number;
        mimes: string;
    };
    skills: { id: number; name: string; icon: string | null }[];
    mcpEnabled: boolean;
    webEnabled: boolean;
    imageEnabled: boolean;
    speechEnabled: boolean;
    continuePrompt?: string;
}>();
</script>

<template>
    <Head title="Chat" />

    <div class="h-[calc(100svh-4rem)] w-full">
        <ChatPanel
            :providers="providers"
            :default-model="defaultModel"
            :conversations="conversations"
            :uploads="uploads"
            :skills="skills"
            :mcp-enabled="mcpEnabled"
            :web-enabled="webEnabled"
            :image-enabled="imageEnabled"
            :speech-enabled="speechEnabled"
            :continue-prompt="continuePrompt"
            full-bleed
        />
    </div>
</template>
