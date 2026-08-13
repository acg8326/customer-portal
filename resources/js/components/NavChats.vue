<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Plus, Star, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { jsonHeaders } from '@/lib/http';
import { chat } from '@/routes';

/**
 * Chat history in the app rail, claude.ai-style, replacing the second column
 * that used to sit between the nav and the chat itself. The list is a shared
 * Inertia prop, so it's the same on every page and survives navigation.
 *
 * Selecting a chat goes through `/chat?c={id}` — the same route the ⌘K search
 * dialog already uses, so there's one way into a conversation rather than two.
 */
type SidebarChat = { id: number; title: string; starred?: boolean };

const page = usePage();

const chats = computed<SidebarChat[]>(() => page.props.recentChats ?? []);

/** The open chat, read from the URL rather than tracked separately. */
const activeId = computed(() => {
    const id = Number(new URLSearchParams(page.url.split('?')[1]).get('c'));

    return Number.isInteger(id) && id > 0 ? id : null;
});

const onChatPage = computed(() => page.url.split('?')[0] === '/chat');

// Starred first, mirroring the order the server sends.
const groups = computed(() =>
    [
        { label: 'Starred', items: chats.value.filter((c) => c.starred) },
        { label: 'Recents', items: chats.value.filter((c) => !c.starred) },
    ].filter((g) => g.items.length > 0),
);

function open(id: number) {
    router.visit(`/chat?c=${id}`);
}

function newChat() {
    router.visit(chat.url());
}

async function toggleStar(id: number) {
    await fetch(`/chat/conversations/${id}/star`, {
        method: 'POST',
        headers: jsonHeaders(),
    });

    refresh();
}

async function remove(id: number) {
    await fetch(`/chat/conversations/${id}`, {
        method: 'DELETE',
        headers: jsonHeaders(),
    });

    // Deleting the chat you're reading would leave the page showing a
    // transcript that no longer exists, so land on a new chat instead.
    if (activeId.value === id && onChatPage.value) {
        newChat();

        return;
    }

    refresh();
}

/** Pull just this prop back down; the rest of the page stays put. */
function refresh() {
    router.reload({ only: ['recentChats'] });
}
</script>

<template>
    <!-- Hidden when the rail is collapsed to icons: a list of titles has no
     icon form, and squeezing it into 3rem would just clip every row. -->
    <SidebarGroup class="px-2 py-0 group-data-[collapsible=icon]:hidden">
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton tooltip="New chat" @click="newChat">
                    <Plus />
                    <span>New chat</span>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>

        <template v-for="group in groups" :key="group.label">
            <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
            <SidebarMenu>
                <SidebarMenuItem v-for="c in group.items" :key="c.id">
                    <SidebarMenuButton
                        class="pr-14"
                        :is-active="onChatPage && c.id === activeId"
                        @click="open(c.id)"
                    >
                        <!-- The rail is narrow and auto-titles are long, so
                         hovering has to be able to reveal the rest. -->
                        <span class="truncate" :title="c.title">{{
                            c.title
                        }}</span>
                    </SidebarMenuButton>

                    <!-- Absolute so they overlay the title rather than
                     competing with it for the row's width. -->
                    <div
                        class="absolute inset-y-0 right-1 flex items-center gap-0.5"
                    >
                        <button
                            type="button"
                            :aria-label="
                                c.starred ? 'Unstar chat' : 'Star chat'
                            "
                            :title="c.starred ? 'Unstar chat' : 'Star chat'"
                            class="rounded p-1 transition"
                            :class="
                                c.starred
                                    ? 'text-brand-gold'
                                    : 'text-muted-foreground opacity-0 group-hover/menu-item:opacity-100 hover:bg-sidebar-accent focus-visible:opacity-100'
                            "
                            @click.stop="toggleStar(c.id)"
                        >
                            <Star
                                class="size-3.5"
                                :fill="c.starred ? 'currentColor' : 'none'"
                            />
                        </button>
                        <button
                            type="button"
                            aria-label="Delete chat"
                            title="Delete chat"
                            class="rounded p-1 text-muted-foreground opacity-0 transition group-hover/menu-item:opacity-100 hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100"
                            @click.stop="remove(c.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </SidebarMenuItem>
            </SidebarMenu>
        </template>
    </SidebarGroup>
</template>
