<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
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
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col overflow-hidden p-10 text-white lg:flex dark:border-r"
        >
            <div
                class="absolute inset-0 bg-gradient-to-br from-zinc-900 via-zinc-900 to-indigo-950"
            />
            <div
                class="absolute -top-24 -right-24 size-[28rem] rounded-full bg-indigo-500/20 blur-3xl"
            />
            <div
                class="absolute -bottom-32 -left-16 size-[24rem] rounded-full bg-blue-500/10 blur-3xl"
            />

            <Link
                :href="home()"
                class="relative z-20 flex items-center text-lg font-medium"
            >
                <AppLogoIcon class="mr-2 size-8 fill-current text-white" />
                {{ name }}
            </Link>

            <div class="relative z-20 mt-auto">
                <h2 class="text-3xl font-semibold tracking-tight">
                    Welcome to the Customer Portal
                </h2>
                <p class="mt-3 max-w-md text-sm text-white/70">
                    Sign in to access your dashboard, manage your account, and
                    pick up right where you left off.
                </p>
            </div>
        </div>
        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
