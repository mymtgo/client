<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, Layers, Trophy, Swords } from 'lucide-vue-next';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import LeaguesIndexController from '@/actions/App/Http/Controllers/Leagues/IndexController';
import OpponentsIndexController from '@/actions/App/Http/Controllers/Opponents/IndexController';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'Decks',     icon: Layers,          href: DecksIndexController.url() },
    { label: 'Leagues',   icon: Trophy,           href: LeaguesIndexController.url() },
    { label: 'Opponents', icon: Swords,            href: OpponentsIndexController.url() },
];

const isActive = (href: string) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};
</script>

<template>
    <nav class="flex h-10 shrink-0 items-center gap-1 border-b bg-background px-4">
        <Link
            v-for="item in nav"
            :key="item.label"
            :href="item.href"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="isActive(item.href)
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground'"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
    </nav>
</template>
