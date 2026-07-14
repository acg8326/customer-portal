<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'General',
        href: '/settings/general',
    },
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Skills',
        href: '/settings/skills',
    },
];

// Searchable index of every setting — label + keywords + the page it lives
// on. Typing filters this list; clicking a result jumps to the page.
const settingsIndex: {
    label: string;
    page: string;
    href: string;
    keywords: string;
}[] = [
    {
        label: 'Theme',
        page: 'General',
        href: '/settings/general',
        keywords: 'appearance light dark system mode color',
    },
    {
        label: 'Language',
        page: 'General',
        href: '/settings/general',
        keywords: 'reply language auto tagalog english translate',
    },
    {
        label: 'Message font size',
        page: 'General',
        href: '/settings/general',
        keywords: 'text size small medium large chat display',
    },
    {
        label: 'Name & email',
        page: 'Profile',
        href: toUrl(editProfile()),
        keywords: 'account name email address verify',
    },
    {
        label: 'Chat preferences',
        page: 'Profile',
        href: toUrl(editProfile()),
        keywords: 'instructions tone standing preferences aime',
    },
    {
        label: 'Assistant memory',
        page: 'Profile',
        href: toUrl(editProfile()),
        keywords: 'memories remember forget learned facts',
    },
    {
        label: 'Delete account',
        page: 'Profile',
        href: toUrl(editProfile()),
        keywords: 'remove danger erase',
    },
    {
        label: 'Password',
        page: 'Security',
        href: toUrl(editSecurity()),
        keywords: 'change password update',
    },
    {
        label: 'Two-factor authentication',
        page: 'Security',
        href: toUrl(editSecurity()),
        keywords: '2fa totp authenticator recovery codes',
    },
    {
        label: 'Passkeys',
        page: 'Security',
        href: toUrl(editSecurity()),
        keywords: 'webauthn passwordless fingerprint faceid',
    },
    {
        label: 'Skills',
        page: 'Skills',
        href: '/settings/skills',
        keywords: 'presets instructions import starter library skill.md',
    },
];

const query = ref('');

const results = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return [];
    }

    return settingsIndex.filter(
        (s) =>
            s.label.toLowerCase().includes(q) ||
            s.page.toLowerCase().includes(q) ||
            s.keywords.includes(q),
    );
});

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-56">
                <div class="relative mb-3">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="query"
                        type="search"
                        placeholder="Search settings"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                    />
                </div>

                <!-- Search results replace the nav while typing -->
                <div
                    v-if="query.trim()"
                    class="flex flex-col space-y-1"
                    aria-label="Settings search results"
                >
                    <p
                        v-if="results.length === 0"
                        class="px-3 py-2 text-sm text-muted-foreground"
                    >
                        No settings match “{{ query.trim() }}”.
                    </p>
                    <Link
                        v-for="r in results"
                        :key="r.page + r.label"
                        :href="r.href"
                        class="rounded-md px-3 py-2 text-sm hover:bg-muted"
                        @click="query = ''"
                    >
                        <span class="font-medium">{{ r.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            r.page
                        }}</span>
                    </Link>
                </div>

                <nav
                    v-else
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="min-w-0 flex-1">
                <section class="w-full space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
