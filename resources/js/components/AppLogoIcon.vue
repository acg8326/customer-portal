<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import logoUrl from '@/assets/brand/cwgp-logo.webp';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        :src="logoUrl"
        alt="CW Global People"
        class="object-contain"
        :class="className"
        v-bind="$attrs"
    />
</template>
