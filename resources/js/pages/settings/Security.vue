<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
    mustChangePassword: boolean;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Security"
            description="Password, two-factor authentication, and passkeys"
        />

        <div
            v-if="props.mustChangePassword"
            class="rounded-lg border border-brand-gold/40 bg-brand-gold/5 px-4 py-3 text-sm"
        >
            <strong class="font-medium">Set a new password to continue.</strong>
            An admin created this account for you — replace the password they
            gave you with one only you know before using the rest of the app.
        </div>

        <SettingsSection label="Password">
            <p class="mb-4 text-sm text-muted-foreground">
                Ensure your account is using a long, random password to stay
                secure.
            </p>

            <Form
                v-bind="SecurityController.update.form()"
                :options="{
                    preserveScroll: true,
                }"
                reset-on-success
                :reset-on-error="[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="current_password">Current password</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                        placeholder="Current password"
                    />
                    <InputError :message="errors.current_password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">New password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        placeholder="New password"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        placeholder="Confirm password"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        :disabled="processing"
                        data-test="update-password-button"
                    >
                        Save
                    </Button>
                </div>
            </Form>
        </SettingsSection>

        <SettingsSection label="Two-factor authentication">
            <ManageTwoFactor
                :canManageTwoFactor="canManageTwoFactor"
                :requiresConfirmation="requiresConfirmation"
                :twoFactorEnabled="twoFactorEnabled"
            />
        </SettingsSection>

        <SettingsSection label="Passkeys">
            <ManagePasskeys
                :canManagePasskeys="canManagePasskeys"
                :passkeys="passkeys"
            />
        </SettingsSection>
    </div>
</template>
