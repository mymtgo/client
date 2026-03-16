<script setup lang="ts">
import ArchetypesIndexController from '@/actions/App/Http/Controllers/Archetypes/IndexController';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import LeaguesIndexController from '@/actions/App/Http/Controllers/Leagues/IndexController';
import OpponentsIndexController from '@/actions/App/Http/Controllers/Opponents/IndexController';
import { Link, usePage } from '@inertiajs/vue3';
import { Bug, Layers, LayoutDashboard, Puzzle, Swords, Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'Decks', icon: Layers, href: DecksIndexController.url() },
    { label: 'Leagues', icon: Trophy, href: LeaguesIndexController.url() },
    { label: 'Opponents', icon: Swords, href: OpponentsIndexController.url() },
    { label: 'Archetypes', icon: Puzzle, href: ArchetypesIndexController.url() },
];

const debugMode = computed(() => (usePage().props as Record<string, unknown>).debugMode as boolean);

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
            class="relative inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors"
            :class="{
                'text-background-accent border-black shadow-inner shadow-black outline-[1px] outline-white/10': isActive(item.href),
                'bevel border-black/60 text-white hover:bg-accent/50 hover:text-accent-foreground': !isActive(item.href),
            }"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
        <Link
            v-if="debugMode"
            href="/debug/matches"
            class="relative inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium text-white transition-colors"
            :class="{
                'text-background-accent border-black shadow-inner shadow-black outline-[1px] outline-white/10': isActive('/debug'),
                'bevel border-black/60 hover:bg-accent/50 hover:text-accent-foreground': !isActive('/debug'),
            }"
        >
            <Bug class="size-4" />
            Debug
        </Link>
    </nav>
</template>
