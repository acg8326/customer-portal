<script setup lang="ts">
// The shared visual language of the Settings pages (General is the
// reference): an uppercase mini-label above a rounded bordered card.
// - card: padded panel (forms, mixed content)
// - rows: divide-y list — each child row brings its own px-4 py-3
// - plain: label only, content unwrapped (for self-styled components)
withDefaults(
    defineProps<{
        label?: string;
        variant?: 'card' | 'rows' | 'plain';
    }>(),
    { label: undefined, variant: 'card' },
);
</script>

<template>
    <section>
        <p
            v-if="label"
            class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
        >
            {{ label }}
        </p>
        <div
            :class="{
                'rounded-xl border p-5': variant === 'card',
                'divide-y rounded-xl border': variant === 'rows',
            }"
        >
            <slot />
        </div>
    </section>
</template>
