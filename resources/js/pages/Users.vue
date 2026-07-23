<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Crown,
    Plus,
    Settings2,
    Shield,
    Trash2,
    User as UserIcon,
} from '@lucide/vue';
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
    role: 'super_admin' | 'admin' | 'user';
    created_at: string | null;
    is_self: boolean;
    assigned_model: string | null;
    token_limit: number | null;
};

const props = defineProps<{
    users: ManagedUser[];
    canGovern: boolean;
    models: { value: string; label: string }[];
}>();

const page = usePage();
const flash = computed(
    () =>
        page.props.flash as { success?: string | null; error?: string | null },
);

// Only the super admin may remove a super admin account (enforced
// server-side too — this just hides the dead-end button).
const canRemove = (u: ManagedUser) =>
    !u.is_self &&
    (u.role !== 'super_admin' || page.props.auth?.user?.role === 'super_admin');

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

// --- Per-user model + token limit (super admin) -----------------------------------

const modelLabel = (value: string | null) =>
    props.models.find((m) => m.value === value)?.label ?? value;

// How a user's pinned model reads in the table.
const modelSummary = (u: ManagedUser) =>
    u.assigned_model ? modelLabel(u.assigned_model) : 'Free choice';

// How a user's token cap reads in the table.
const limitSummary = (u: ManagedUser) => {
    if (u.token_limit === null) {
        return 'Workspace limit';
    }

    return u.token_limit === 0
        ? 'Unlimited'
        : `${u.token_limit.toLocaleString()} tokens`;
};

const editing = ref<ManagedUser | null>(null);
const modelDraft = ref('default');
const limitDraft = ref<string | number>('');
const savingLimits = ref(false);

function openEdit(u: ManagedUser) {
    editing.value = u;
    modelDraft.value = u.assigned_model ?? 'default';
    limitDraft.value = u.token_limit === null ? '' : String(u.token_limit);
}

function saveLimits() {
    if (editing.value === null || savingLimits.value) {
        return;
    }

    savingLimits.value = true;

    // A type="number" input can hand us a number; normalise before trimming.
    const trimmed = String(limitDraft.value ?? '').trim();

    router.patch(
        `/dashboard/users/${editing.value.id}/limits`,
        {
            assigned_model: modelDraft.value,
            token_limit: trimmed === '' ? null : Number(trimmed),
        },
        {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
            onFinish: () => (savingLimits.value = false),
        },
    );
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
                        <th v-if="canGovern" class="px-4 py-2.5 font-medium">
                            Model / limit
                        </th>
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
                                    u.role === 'user'
                                        ? 'border-border bg-muted/60 text-muted-foreground'
                                        : 'border-brand-gold/40 bg-brand-gold/10 text-brand-gold'
                                "
                            >
                                <component
                                    :is="
                                        u.role === 'super_admin'
                                            ? Crown
                                            : u.role === 'admin'
                                              ? Shield
                                              : UserIcon
                                    "
                                    class="size-3"
                                />
                                {{
                                    u.role === 'super_admin'
                                        ? 'Super admin'
                                        : u.role === 'admin'
                                          ? 'Admin'
                                          : 'User'
                                }}
                            </span>
                        </td>
                        <td
                            v-if="canGovern"
                            class="px-4 py-3 text-muted-foreground"
                        >
                            <span class="text-foreground">{{
                                modelSummary(u)
                            }}</span>
                            <span class="mx-1 text-muted-foreground/50">·</span>
                            {{ limitSummary(u) }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ u.created_at ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="canGovern"
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    title="Set model & token limit"
                                    @click="openEdit(u)"
                                >
                                    <Settings2 class="size-4" />
                                </Button>
                                <Button
                                    v-if="canRemove(u)"
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    @click="askRemove(u)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
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

    <!-- Model & token limit (super admin) -->
    <Dialog
        :open="editing !== null"
        @update:open="
            (v) => {
                if (!v) editing = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ editing?.name }} — access</DialogTitle>
                <DialogDescription>
                    Pin the model this member runs on (in the portal and via
                    Claude Code) and cap their token usage. Applies everywhere
                    their account is used.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="saveLimits">
                <div class="space-y-2">
                    <Label for="u-model">Model</Label>
                    <select
                        id="u-model"
                        v-model="modelDraft"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    >
                        <option value="default">Free choice (they pick)</option>
                        <option
                            v-for="m in models"
                            :key="m.value"
                            :value="m.value"
                        >
                            Locked to {{ m.label }}
                        </option>
                    </select>
                </div>

                <div class="space-y-2">
                    <Label for="u-limit">Token limit</Label>
                    <input
                        id="u-limit"
                        v-model="limitDraft"
                        type="number"
                        min="0"
                        step="50000"
                        placeholder="Inherit workspace limit"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                    <p class="text-xs text-muted-foreground">
                        Blank = inherit the workspace limit · 0 = unlimited for
                        this member · per 30-day window.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="savingLimits">
                        {{ savingLimits ? 'Saving…' : 'Save' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
