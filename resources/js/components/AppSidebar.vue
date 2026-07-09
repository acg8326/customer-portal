<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    FolderOpen,
    LayoutGrid,
    MessageSquare,
    Plug,
    Search,
    Users,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import ChatSearchDialog from '@/components/ChatSearchDialog.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { chat, dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin');

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Chat',
        href: chat(),
        icon: MessageSquare,
    },
    {
        title: 'Projects',
        href: '/projects',
        icon: FolderOpen,
    },
    {
        title: 'Integrations',
        href: '/integrations',
        icon: Plug,
    },
    // Admin-only.
    ...(isAdmin.value ? [{ title: 'Users', href: '/users', icon: Users }] : []),
]);

const searchOpen = ref(false);

function onKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        searchOpen.value = true;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup class="px-2 py-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            tooltip="Search chats (⌘K)"
                            @click="searchOpen = true"
                        >
                            <Search />
                            <span>Search</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>

            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />

    <ChatSearchDialog v-model:open="searchOpen" />
</template>
