<script setup lang="ts">
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';

// Read-only rendered Markdown (shared-conversation pages). Same pipeline as
// ChatPanel: marked (GFM) then DOMPurify — model output is never trusted raw.
const props = defineProps<{ content: string }>();

marked.setOptions({ gfm: true, breaks: true });

const html = computed(() =>
    DOMPurify.sanitize(
        marked.parse(props.content ?? '', { async: false }) as string,
        { ADD_ATTR: ['target', 'rel'] },
    ),
);
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- sanitized above -->
    <div class="md" v-html="html" />
</template>

<style scoped>
.md {
    line-height: 1.6;
    word-break: break-word;
}

.md :deep(> *:first-child) {
    margin-top: 0;
}

.md :deep(> *:last-child) {
    margin-bottom: 0;
}

.md :deep(p),
.md :deep(ul),
.md :deep(ol),
.md :deep(pre),
.md :deep(blockquote),
.md :deep(table) {
    margin: 0.6em 0;
}

.md :deep(ul),
.md :deep(ol) {
    padding-left: 1.35em;
}

.md :deep(li) {
    margin: 0.2em 0;
}

.md :deep(h1),
.md :deep(h2),
.md :deep(h3),
.md :deep(h4) {
    margin: 0.9em 0 0.4em;
    font-weight: 600;
    line-height: 1.3;
}

.md :deep(h1) {
    font-size: 1.3em;
}
.md :deep(h2) {
    font-size: 1.2em;
}
.md :deep(h3) {
    font-size: 1.1em;
}

.md :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.md :deep(strong) {
    font-weight: 600;
}

.md :deep(code) {
    font-family:
        ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono',
        monospace;
    font-size: 0.85em;
    background: color-mix(in srgb, var(--foreground) 10%, transparent);
    padding: 0.15em 0.35em;
    border-radius: 0.35rem;
}

.md :deep(pre) {
    background: color-mix(in srgb, var(--foreground) 8%, transparent);
    border: 1px solid var(--border);
    border-radius: 0.6rem;
    padding: 0.75em 0.9em;
    overflow-x: auto;
}

.md :deep(pre code) {
    background: transparent;
    padding: 0;
    font-size: 0.82em;
    line-height: 1.5;
}

.md :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 0.9em;
    color: var(--muted-foreground);
}

.md :deep(table) {
    display: block;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.9em;
}

.md :deep(th),
.md :deep(td) {
    border: 1px solid var(--border);
    padding: 0.4em 0.65em;
    text-align: left;
    vertical-align: top;
}

.md :deep(th) {
    background: color-mix(in srgb, var(--foreground) 6%, transparent);
    font-weight: 600;
}

.md :deep(hr) {
    border: none;
    border-top: 1px solid var(--border);
    margin: 1em 0;
}

.md :deep(img) {
    max-width: 100%;
    border-radius: 0.5rem;
}
</style>
