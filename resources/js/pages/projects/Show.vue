<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FolderOpen, PanelRightOpen } from '@lucide/vue';
import { computed, ref } from 'vue';
import ChatPanel from '@/components/ChatPanel.vue';
import ProjectKnowledge from '@/components/ProjectKnowledge.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: '/projects' }],
        fullWidth: true,
    },
});

defineProps<{
    project: {
        id: number;
        name: string;
        instructions: string | null;
    };
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
    netsuiteAccounts: {
        id: number;
        label: string;
        accountId: string;
        isDefault: boolean;
    }[];
    webEnabled: boolean;
    imageEnabled: boolean;
    speechEnabled: boolean;
    continuePrompt?: string;
    files: { id: number; name: string; size: number }[];
    fileLimits: { maxFiles: number; mimes: string };
}>();

const panelOpen = ref(false);

const railClass = computed(() =>
    panelOpen.value
        ? 'fixed inset-y-0 right-0 z-40 flex w-80 flex-col border-l bg-card shadow-xl lg:static lg:z-auto lg:rounded-2xl lg:border lg:shadow-sm'
        : 'hidden rounded-2xl border bg-card shadow-sm lg:flex lg:w-80 lg:flex-col',
);
</script>

<template>
    <Head :title="project.name" />

    <div class="h-[calc(100svh-4rem)] w-full p-4">
        <div class="flex h-full gap-4">
            <!-- Chat -->
            <div class="min-w-0 flex-1">
                <ChatPanel
                    :providers="providers"
                    :default-model="defaultModel"
                    :conversations="conversations"
                    :uploads="uploads"
                    :skills="skills"
                    :mcp-enabled="mcpEnabled"
                    :netsuite-accounts="netsuiteAccounts"
                    :web-enabled="webEnabled"
                    :image-enabled="imageEnabled"
                    :speech-enabled="speechEnabled"
                    :continue-prompt="continuePrompt"
                    :project-id="project.id"
                >
                    <template #brand>
                        <Link
                            href="/projects"
                            class="rounded p-1 text-muted-foreground hover:bg-accent"
                            aria-label="Back to projects"
                        >
                            <ArrowLeft class="size-4" />
                        </Link>
                        <div
                            class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-brand-navy to-brand-gold text-white shadow-sm"
                        >
                            <FolderOpen class="size-4" />
                        </div>
                        <div class="min-w-0 leading-tight">
                            <p class="truncate text-sm font-semibold">
                                {{ project.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">Project</p>
                        </div>
                        <button
                            type="button"
                            class="ml-1 rounded p-1 text-muted-foreground hover:bg-accent lg:hidden"
                            aria-label="Project knowledge"
                            @click="panelOpen = true"
                        >
                            <PanelRightOpen class="size-4" />
                        </button>
                    </template>

                    <template #empty>
                        Ask anything about {{ project.name }}. I'll use this
                        project's instructions.
                    </template>
                </ChatPanel>
            </div>

            <!-- Mobile backdrop -->
            <div
                v-if="panelOpen"
                class="fixed inset-0 z-30 bg-black/40 lg:hidden"
                @click="panelOpen = false"
            />

            <!-- Project knowledge panel -->
            <aside :class="railClass">
                <ProjectKnowledge
                    :project="project"
                    :files="files"
                    :file-limits="fileLimits"
                    @saved="panelOpen = false"
                />
            </aside>
        </div>
    </div>
</template>
