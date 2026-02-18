<script setup lang="ts">
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, Layers, Trophy, Settings, Sun, Moon } from 'lucide-vue-next';
import { ref } from 'vue';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import LeaguesIndexController from '@/actions/App/Http/Controllers/Leagues/IndexController';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'Decks',     icon: Layers,           href: DecksIndexController.url() },
    { label: 'Leagues',   icon: Trophy,            href: LeaguesIndexController.url() },
];

const isActive = (href: string) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};

// Theme toggle — syncs with the class on <html>
const isDark = ref(document.documentElement.classList.contains('dark'));

const toggleTheme = () => {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
};
</script>

<template>
    <Sidebar collapsible="offcanvas">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child class="data-[slot=sidebar-menu-button]:p-1.5!">
                        <Link :href="DashboardController.url()">
                            <span class="text-base font-semibold">mymtgo</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarMenu class="px-2 py-1">
                <SidebarMenuItem v-for="item in nav" :key="item.label">
                    <SidebarMenuButton as-child :is-active="isActive(item.href)">
                        <Link :href="item.href">
                            <component :is="item.icon" class="size-4" />
                            <span>{{ item.label }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child>
                        <Link href="/settings">
                            <Settings class="size-4" />
                            <span>Settings</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton @click="toggleTheme">
                        <Sun v-if="isDark" class="size-4" />
                        <Moon v-else class="size-4" />
                        <span>{{ isDark ? 'Light mode' : 'Dark mode' }}</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarFooter>
    </Sidebar>
</template>
