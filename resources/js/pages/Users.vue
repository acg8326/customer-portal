<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Shield, Trash2, User as UserIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: '/users' }],
        fullWidth: true,
    },
});

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'user';
    created_at: string | null;
    is_self: boolean;
};

defineProps<{ users: ManagedUser[] }>();

const page = usePage();
const flash = computed(
    () =>
        page.props.flash as { success?: string | null; error?: string | null },
);

const open = ref(false);
const form = useForm<{
    name: string;
    email: string;
    password: string;
    role: 'admin' | 'user';
}>({ name: '', email: '', password: '', role: 'user' });

function openAdd() {
    form.clearErrors();
    form.reset();
    open.value = true;
}

function save() {
    form.post('/users', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

const deleting = ref<ManagedUser | null>(null);

function askRemove(u: ManagedUser) {
    if (!u.is_self) {
        deleting.value = u;
    }
}

function confirmRemove() {
    if (deleting.value === null) {
        return;
    }

    router.delete(`/users/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
        },
    });
}
</script>

<template>
    <Head title="Users" />

    <div class="w-full p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
                <p class="text-sm text-muted-foreground">
                    Add or remove people who can sign in. There is no public
                    registration — only admins manage access here.
                </p>
            </div>
            <Button class="shrink-0" @click="openAdd">
                <Plus class="size-4" />
                Add user
            </Button>
        </div>

        <div
            v-if="flash.success"
            class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
        >
            {{ flash.success }}
        </div>
        <div
            v-if="flash.error"
            class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ flash.error }}
        </div>

        <div class="overflow-hidden rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Name</th>
                        <th class="px-4 py-2.5 font-medium">Email</th>
                        <th class="px-4 py-2.5 font-medium">Role</th>
                        <th class="px-4 py-2.5 font-medium">Added</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="u in users"
                        :key="u.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ u.name }}
                            <span
                                v-if="u.is_self"
                                class="ml-1 text-xs text-muted-foreground"
                                >(you)</span
                            >
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ u.email }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium"
                                :class="
                                    u.role === 'admin'
                                        ? 'border-brand-gold/40 bg-brand-gold/10 text-brand-gold'
                                        : 'border-border bg-muted/60 text-muted-foreground'
                                "
                            >
                                <component
                                    :is="u.role === 'admin' ? Shield : UserIcon"
                                    class="size-3"
                                />
                                {{ u.role === 'admin' ? 'Admin' : 'User' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ u.created_at ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="!u.is_self"
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                @click="askRemove(u)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add user modal -->
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add user</DialogTitle>
                <DialogDescription>
                    They'll sign in with this email and password. Share the
                    credentials securely; they can change their password after
                    logging in.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="save">
                <div class="space-y-2">
                    <Label for="u-name">Name</Label>
                    <Input id="u-name" v-model="form.name" autofocus />
                    <p v-if="form.errors.name" class="text-sm text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="u-email">Email</Label>
                    <Input id="u-email" v-model="form.email" type="email" />
                    <p
                        v-if="form.errors.email"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.email }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="u-password">Password</Label>
                    <Input
                        id="u-password"
                        v-model="form.password"
                        type="password"
                    />
                    <p
                        v-if="form.errors.password"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.password }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label>Role</Label>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                form.role === 'user' ? 'default' : 'outline'
                            "
                            @click="form.role = 'user'"
                        >
                            <UserIcon class="size-4" />
                            User
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                form.role === 'admin' ? 'default' : 'outline'
                            "
                            @click="form.role = 'admin'"
                        >
                            <Shield class="size-4" />
                            Admin
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Admins can add and remove users. Users cannot.
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.name.trim() === '' ||
                            form.email.trim() === '' ||
                            form.password === ''
                        "
                    >
                        Add user
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Delete confirmation -->
    <Dialog
        :open="deleting !== null"
        @update:open="
            (v) => {
                if (!v) deleting = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Remove {{ deleting?.name }}?</DialogTitle>
                <DialogDescription>
                    This permanently removes
                    <span class="font-medium">{{ deleting?.email }}</span> and
                    they will no longer be able to sign in. This cannot be
                    undone.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button variant="destructive" @click="confirmRemove">
                    Remove user
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
