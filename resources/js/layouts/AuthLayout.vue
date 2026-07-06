<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import SonarBackground from '@/components/SonarBackground.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-[#060D1A] px-6 py-12 text-[#E6EDF5] antialiased"
    >
        <!-- Animated sonar field -->
        <SonarBackground />

        <!-- Depth wash: lifts the centre, sinks the edges -->
        <div
            class="pointer-events-none absolute inset-0"
            style="
                background:
                    radial-gradient(
                        120% 80% at 50% 18%,
                        rgba(212, 165, 55, 0.08),
                        transparent 55%
                    ),
                    radial-gradient(
                        100% 100% at 50% 120%,
                        rgba(6, 13, 26, 0),
                        #060d1a 70%
                    );
            "
        />

        <main class="relative z-10 w-full max-w-md">
            <!-- Brand mark -->
            <Link
                :href="home()"
                class="group mb-8 flex flex-col items-center gap-3"
            >
                <span
                    class="relative flex size-12 items-center justify-center rounded-2xl border border-[#D4A537]/30 bg-[#D4A537]/10 shadow-[0_0_40px_-8px_rgba(212,165,55,0.6)] transition-transform group-hover:scale-105"
                >
                    <AppLogoIcon class="size-6 fill-current text-[#D4A537]" />
                </span>
                <span
                    class="font-mono text-[11px] tracking-[0.35em] text-[#7E8CA0] uppercase"
                >
                    {{ name }} · CWGP-AIMe
                </span>
            </Link>

            <!-- Glass card -->
            <div
                class="relative rounded-2xl border border-white/10 bg-white/[0.03] p-8 shadow-[0_24px_80px_-24px_rgba(0,0,0,0.9)] backdrop-blur-xl sm:p-10"
            >
                <!-- accent hairline along the top edge -->
                <div
                    class="pointer-events-none absolute inset-x-8 top-0 h-px bg-gradient-to-r from-transparent via-[#D4A537]/70 to-transparent"
                />

                <div class="mb-7 space-y-1.5">
                    <h1
                        v-if="title"
                        class="text-2xl font-semibold tracking-tight text-white"
                    >
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-[#9AA7BC]">
                        {{ description }}
                    </p>
                </div>

                <slot />
            </div>

            <!-- Status line -->
            <p
                class="mt-6 text-center font-mono text-[11px] tracking-[0.25em] text-[#46556B] uppercase"
            >
                <span class="text-[#D4A537]">●</span> Secure connection
            </p>
        </main>
    </div>
</template>
