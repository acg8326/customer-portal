<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import SettingsSection from '@/components/SettingsSection.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'General settings',
                href: '/settings/general',
            },
        ],
    },
});

const props = defineProps<{
    preferredLanguage: string | null;
    languages: string[];
}>();

function setLanguage(e: Event) {
    router.patch(
        '/settings/language',
        { language: (e.target as HTMLSelectElement).value },
        { preserveScroll: true },
    );
}

// Message font size — a device preference (like theme), kept in the browser.
// ChatPanel reads it on mount and sizes message text accordingly.
const FONT_SIZE_KEY = 'chat:fontSize';
const fontSizes = [
    { value: 'sm', label: 'Small' },
    { value: 'base', label: 'Medium' },
    { value: 'lg', label: 'Large' },
];

const fontSize = ref(localStorage.getItem(FONT_SIZE_KEY) ?? 'base');

function setFontSize(e: Event) {
    fontSize.value = (e.target as HTMLSelectElement).value;
    localStorage.setItem(FONT_SIZE_KEY, fontSize.value);
}
</script>

<template>
    <Head title="General settings" />

    <h1 class="sr-only">General settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="General"
            description="Theme, language, and how chat messages are displayed"
        />

        <SettingsSection label="Appearance" variant="rows">
            <div class="flex items-center justify-between gap-4 px-4 py-3">
                <span class="text-sm font-medium">Theme</span>
                <AppearanceTabs />
            </div>

            <div class="flex items-center justify-between gap-4 px-4 py-3">
                <div>
                    <span class="text-sm font-medium">Language</span>
                    <p class="text-xs text-muted-foreground">
                        AiMe answers in it — "Auto" matches the language you
                        write in
                    </p>
                </div>
                <select
                    id="preferred-language"
                    class="h-9 w-48 shrink-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    :value="preferredLanguage ?? 'auto'"
                    @change="setLanguage"
                >
                    <option value="auto">Auto</option>
                    <option
                        v-for="lang in props.languages"
                        :key="lang"
                        :value="lang"
                    >
                        {{ lang }}
                    </option>
                </select>
            </div>

            <div class="flex items-center justify-between gap-4 px-4 py-3">
                <span class="text-sm font-medium">Message font size</span>
                <select
                    id="message-font-size"
                    class="h-9 w-36 shrink-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    :value="fontSize"
                    @change="setFontSize"
                >
                    <option
                        v-for="s in fontSizes"
                        :key="s.value"
                        :value="s.value"
                    >
                        {{ s.label }}
                    </option>
                </select>
            </div>
        </SettingsSection>
    </div>
</template>
