<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Sign in',
        description: 'Enter your credentials to access the portal.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

// Field styling tuned for the dark glass card.
const fieldClass =
    'h-11 rounded-lg border-white/10 bg-white/[0.04] text-white placeholder:text-[#566377] focus-visible:border-[#2DE2C8] focus-visible:ring-[3px] focus-visible:ring-[#2DE2C8]/25';
const labelClass = 'text-sm font-medium text-[#C7D2E0]';
</script>

<template>
    <Head title="Sign in" />

    <div
        v-if="status"
        class="mb-5 rounded-lg border border-[#2DE2C8]/30 bg-[#2DE2C8]/10 px-4 py-3 text-center text-sm font-medium text-[#7CEBD8]"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-2">
            <Label for="email" :class="labelClass">Email address</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                autofocus
                :tabindex="1"
                autocomplete="email"
                placeholder="you@example.com"
                :class="fieldClass"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <Label for="password" :class="labelClass">Password</Label>
                <TextLink
                    v-if="canResetPassword"
                    :href="request()"
                    class="text-sm !text-[#2DE2C8] !decoration-[#2DE2C8]/40 hover:!text-[#5BEAD6]"
                    :tabindex="5"
                >
                    Forgot password?
                </TextLink>
            </div>
            <PasswordInput
                id="password"
                name="password"
                required
                :tabindex="2"
                autocomplete="current-password"
                placeholder="••••••••"
                :class="fieldClass"
            />
            <InputError :message="errors.password" />
        </div>

        <Label
            for="remember"
            class="flex items-center gap-3 text-sm text-[#9AA7BC]"
        >
            <Checkbox
                id="remember"
                name="remember"
                :tabindex="3"
                class="border-white/25 data-[state=checked]:border-[#2DE2C8] data-[state=checked]:bg-[#2DE2C8] data-[state=checked]:text-[#041014]"
            />
            <span>Keep me signed in</span>
        </Label>

        <Button
            type="submit"
            class="group mt-1 h-11 w-full rounded-lg bg-[#2DE2C8] font-semibold text-[#041014] shadow-[0_10px_34px_-10px_rgba(45,226,200,0.8)] transition-colors hover:bg-[#5BEAD6]"
            :tabindex="4"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            <template v-else>
                Sign in
                <ArrowRight
                    class="size-4 transition-transform group-hover:translate-x-0.5"
                />
            </template>
        </Button>
    </Form>
</template>
