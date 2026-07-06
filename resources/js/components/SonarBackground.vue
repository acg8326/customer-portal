<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const canvas = ref<HTMLCanvasElement | null>(null);
let raf = 0;
let start = 0;

// Sonar palette — CW Global People brand (gold + navy).
const ACCENT = '212, 165, 55'; // #D4A537 gold
const SIGNAL = '37, 78, 140'; // #254E8C navy

function draw(now: number) {
    const el = canvas.value;

    if (!el) {
        return;
    }

    const ctx = el.getContext('2d');

    if (!ctx) {
        return;
    }

    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const w = el.clientWidth;
    const h = el.clientHeight;

    if (el.width !== w * dpr || el.height !== h * dpr) {
        el.width = w * dpr;
        el.height = h * dpr;
    }

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    if (!start) {
        start = now;
    }

    const t = (now - start) / 1000;

    // The ping originates from depth, low and slightly off-centre.
    const ox = w * 0.5;
    const oy = h * 0.92;
    const maxR = Math.hypot(Math.max(ox, w - ox), oy) * 1.05;

    // Faint polar range arcs — the instrument's static grid.
    ctx.lineWidth = 1;

    for (let i = 1; i <= 7; i++) {
        const r = (maxR / 7) * i;
        ctx.beginPath();
        ctx.arc(ox, oy, r, Math.PI, Math.PI * 2);
        ctx.strokeStyle = `rgba(${ACCENT}, 0.04)`;
        ctx.stroke();
    }

    // Bearing lines fanning up from the origin.
    for (let i = -3; i <= 3; i++) {
        const a = -Math.PI / 2 + (i * Math.PI) / 9;
        ctx.beginPath();
        ctx.moveTo(ox, oy);
        ctx.lineTo(ox + Math.cos(a) * maxR, oy + Math.sin(a) * maxR);
        ctx.strokeStyle = `rgba(${ACCENT}, 0.025)`;
        ctx.stroke();
    }

    // Expanding ping rings — the one moving element.
    const RINGS = 4;
    const PERIOD = 6; // seconds for a ring to travel full range

    for (let i = 0; i < RINGS; i++) {
        const phase = (((t / PERIOD + i / RINGS) % 1) + 1) % 1;
        const r = phase * maxR;
        const fade = Math.sin(phase * Math.PI); // in then out
        const alpha = fade * 0.7;

        if (alpha <= 0.01) {
            continue;
        }

        ctx.beginPath();
        ctx.arc(ox, oy, r, Math.PI, Math.PI * 2);
        const grad = ctx.createLinearGradient(0, oy - r, 0, oy);
        grad.addColorStop(0, `rgba(${ACCENT}, ${alpha})`);
        grad.addColorStop(1, `rgba(${SIGNAL}, ${alpha * 0.3})`);
        ctx.strokeStyle = grad;
        ctx.lineWidth = 1.5;
        ctx.shadowColor = `rgba(${ACCENT}, ${alpha * 0.6})`;
        ctx.shadowBlur = 12;
        ctx.stroke();
        ctx.shadowBlur = 0;
    }

    raf = requestAnimationFrame(draw);
}

function drawStatic() {
    // prefers-reduced-motion: a single calm frame, no animation loop.
    start = 0;
    draw(0);
    cancelAnimationFrame(raf);
}

onMounted(() => {
    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    if (reduced) {
        drawStatic();
        window.addEventListener('resize', drawStatic);
    } else {
        raf = requestAnimationFrame(draw);
        window.addEventListener('resize', () => {});
    }
});

onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    window.removeEventListener('resize', drawStatic);
});
</script>

<template>
    <canvas
        ref="canvas"
        class="absolute inset-0 h-full w-full"
        aria-hidden="true"
    />
</template>
