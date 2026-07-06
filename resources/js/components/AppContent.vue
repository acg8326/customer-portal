<script setup lang="ts">
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
    fullWidth?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
    fullWidth: false,
});
const className = computed(() => props.class);
</script>

<template>
    <SidebarInset v-if="props.variant === 'sidebar'" :class="className">
        <slot />
    </SidebarInset>
    <main
        v-else
        class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl"
        :class="[fullWidth ? '' : 'mx-auto max-w-7xl', className]"
    >
        <slot />
    </main>
</template>
