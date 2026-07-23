<script setup lang="ts">
// A keyboard-key pill, e.g. <Kbd>Ctrl</Kbd>+<Kbd>Shift</Kbd>+<Kbd>P</Kbd>.
</script>

<template>
    <kbd
        class="inline-flex items-center rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[0.7rem] leading-none font-medium text-foreground"
    >
        <slot />
    </kbd>
</template>
