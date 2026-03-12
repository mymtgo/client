<script setup lang="ts">
import ArchetypesIndexController from '@/actions/App/Http/Controllers/Archetypes/IndexController';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import LeaguesIndexController from '@/actions/App/Http/Controllers/Leagues/IndexController';
import OpponentsIndexController from '@/actions/App/Http/Controllers/Opponents/IndexController';
import { Link, usePage } from '@inertiajs/vue3';
import { Layers, LayoutDashboard, Puzzle, Swords, Trophy } from 'lucide-vue-next';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'Decks', icon: Layers, href: DecksIndexController.url() },
    { label: 'Leagues', icon: Trophy, href: LeaguesIndexController.url() },
    { label: 'Opponents', icon: Swords, href: OpponentsIndexController.url() },
    { label: 'Archetypes', icon: Puzzle, href: ArchetypesIndexController.url() },
];

const isActive = (href: string) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};
</script>

<template>
    <nav class="flex shrink-0 items-center gap-1 border-b border-black/60 bg-background px-4 py-2 shadow shadow-black/20">
        <Link
            content=""
            v-for="item in nav"
            :key="item.label"
            :href="item.href"
            class="relative inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium text-white transition-colors"
            :class="{
                'border-blue-500/40 bg-blue-500/10 shadow-inner shadow-black text-blue-300 outline-[1px] outline-white/2': isActive(item.href),
                'bevel border-black/60 hover:bg-accent/50 hover:text-accent-foreground': !isActive(item.href),
            }"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
    </nav>
</template>
