<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Crown,
    Pencil,
    Plus,
    RefreshCw,
    Search,
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

type Role = 'super_admin' | 'admin' | 'user';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: Role;
    created_at: string | null;
    is_self: boolean;
};

const props = defineProps<{ users: ManagedUser[] }>();

const page = usePage();
const flash = computed(
    () =>
        page.props.flash as { success?: string | null; error?: string | null },
);

const currentUserRole = computed(() => page.props.auth?.user?.role);

const roleLabel = (role: Role) =>
    role === 'super_admin'
        ? 'Super admin'
        : role === 'admin'
          ? 'Admin'
          : 'User';

const roleIcon = (role: Role) =>
    role === 'super_admin' ? Crown : role === 'admin' ? Shield : UserIcon;

// Only the super admin may act on a super admin account (enforced server-side
// too — this just hides dead-end buttons).
const canManage = (u: ManagedUser) =>
    u.role !== 'super_admin' || currentUserRole.value === 'super_admin';

const canRemove = (u: ManagedUser) => !u.is_self && canManage(u);

// A super admin's role isn't changed here, nor can you change your own role.
const roleEditable = (u: ManagedUser) => !u.is_self && u.role !== 'super_admin';

// --- Search -----------------------------------------------------------------------

const search = ref('');

const filteredUsers = computed(() => {
    const q = search.value.trim().toLowerCase();

    if (!q) {
        return props.users;
    }

    return props.users.filter(
        (u) =>
            u.name.toLowerCase().includes(q) ||
            u.email.toLowerCase().includes(q) ||
            roleLabel(u.role).toLowerCase().includes(q),
    );
});

// --- Add user ---------------------------------------------------------------------

// Ambiguous characters (I, l, 1, O, 0) are excluded so a password read off
// the screen can't be misread — copy/paste is still the expected path.
const PASSWORD_CHARSETS = {
    lower: 'abcdefghijkmnopqrstuvwxyz',
    upper: 'ABCDEFGHJKLMNPQRSTUVWXYZ',
    digits: '23456789',
    symbols: '!@#$%^&*-_=+',
};
const PASSWORD_LENGTH = 20;

function randomInt(max: number): number {
    const buf = new Uint32Array(1);
    crypto.getRandomValues(buf);

    return buf[0] % max;
}

function pick(charset: string): string {
    return charset[randomInt(charset.length)];
}

// Guarantees one char from each category (satisfies the mixedCase/letters/
// numbers/symbols password policy), then fills and shuffles the rest.
function generatePassword(length = PASSWORD_LENGTH): string {
    const all = Object.values(PASSWORD_CHARSETS).join('');
    const chars = [
        pick(PASSWORD_CHARSETS.lower),
        pick(PASSWORD_CHARSETS.upper),
        pick(PASSWORD_CHARSETS.digits),
        pick(PASSWORD_CHARSETS.symbols),
        ...Array.from({ length: length - 4 }, () => pick(all)),
    ];

    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomInt(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}

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
    form.password = generatePassword();
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

const copied = ref<string | null>(null);

function copy(text: string, key: string) {
    navigator.clipboard?.writeText(text).then(() => {
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 1500);
    });
}

// --- Edit user (name / email / role) ----------------------------------------------

const editing = ref<ManagedUser | null>(null);
const editForm = useForm<{
    name: string;
    email: string;
    role: 'admin' | 'user';
}>({ name: '', email: '', role: 'user' });

function openEdit(u: ManagedUser) {
    editing.value = u;
    editForm.clearErrors();
    editForm.name = u.name;
    editForm.email = u.email;
    editForm.role = u.role === 'admin' ? 'admin' : 'user';
}

function saveEdit() {
    if (editing.value === null) {
        return;
    }

    editForm.patch(`/users/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

// --- Delete user ------------------------------------------------------------------

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
                    Add, edit, or remove people who can sign in. There is no
                    public registration — only admins manage access here.
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

        <!-- Search -->
        <div class="relative mb-3 max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="search"
                type="search"
                placeholder="Search by name, email, or role"
                class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
            />
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
                        v-for="u in filteredUsers"
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
                                    :is="roleIcon(u.role)"
                                    class="size-3"
                                />
                                {{ roleLabel(u.role) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ u.created_at ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="canManage(u)"
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    title="Edit user"
                                    @click="openEdit(u)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    v-if="canRemove(u)"
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    title="Remove user"
                                    @click="askRemove(u)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="filteredUsers.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-sm text-muted-foreground"
                        >
                            No users match “{{ search.trim() }}”.
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
                    They'll sign in with this email and the generated password
                    below — copy it now to share it securely. They can change it
                    at Settings → Security after logging in.
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
                    <div class="flex items-center gap-2">
                        <Input
                            id="u-password"
                            v-model="form.password"
                            type="text"
                            class="font-mono"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            title="Generate a new password"
                            @click="form.password = generatePassword()"
                        >
                            <RefreshCw class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            title="Copy password"
                            @click="copy(form.password, 'password')"
                        >
                            <component
                                :is="copied === 'password' ? Check : Copy"
                                class="size-4"
                            />
                        </Button>
                    </div>
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
                            form.password.trim() === ''
                        "
                    >
                        Add user
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Edit user modal -->
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
                <DialogTitle>Edit user</DialogTitle>
                <DialogDescription>
                    Update this member's name, email, and role. They keep their
                    existing password.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="saveEdit">
                <div class="space-y-2">
                    <Label for="e-name">Name</Label>
                    <Input id="e-name" v-model="editForm.name" autofocus />
                    <p
                        v-if="editForm.errors.name"
                        class="text-sm text-destructive"
                    >
                        {{ editForm.errors.name }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="e-email">Email</Label>
                    <Input id="e-email" v-model="editForm.email" type="email" />
                    <p
                        v-if="editForm.errors.email"
                        class="text-sm text-destructive"
                    >
                        {{ editForm.errors.email }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label>Role</Label>
                    <div
                        v-if="editing && roleEditable(editing)"
                        class="flex gap-2"
                    >
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                editForm.role === 'user' ? 'default' : 'outline'
                            "
                            @click="editForm.role = 'user'"
                        >
                            <UserIcon class="size-4" />
                            User
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                editForm.role === 'admin'
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="editForm.role = 'admin'"
                        >
                            <Shield class="size-4" />
                            Admin
                        </Button>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        <span class="font-medium text-foreground">{{
                            editing ? roleLabel(editing.role) : ''
                        }}</span>
                        —
                        {{
                            editing?.is_self
                                ? "you can't change your own role"
                                : 'managed separately'
                        }}.
                    </p>
                    <p
                        v-if="editForm.errors.role"
                        class="text-sm text-destructive"
                    >
                        {{ editForm.errors.role }}
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="
                            editForm.processing ||
                            editForm.name.trim() === '' ||
                            editForm.email.trim() === ''
                        "
                    >
                        {{ editForm.processing ? 'Saving…' : 'Save changes' }}
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
