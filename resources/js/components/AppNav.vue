<script setup lang="ts">
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import LeaguesIndexController from '@/actions/App/Http/Controllers/Leagues/IndexController';
import OpponentsIndexController from '@/actions/App/Http/Controllers/Opponents/IndexController';
import { Link, usePage } from '@inertiajs/vue3';
import { Layers, LayoutDashboard, Swords, Trophy } from 'lucide-vue-next';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'Decks', icon: Layers, href: DecksIndexController.url() },
    { label: 'Leagues', icon: Trophy, href: LeaguesIndexController.url() },
    { label: 'Opponents', icon: Swords, href: OpponentsIndexController.url() },
];

const isActive = (href: string) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};
</script>

<template>
    <nav class="flex shrink-0 items-center gap-1 border-b bg-background px-4 py-2">
        <Link
            content=""
            v-for="item in nav"
            :key="item.label"
            :href="item.href"
            class="relative inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white transition-colors"
            :class="{
                'bg-accent before:absolute before:-bottom-0.5 before:left-1/2 before:h-1 before:w-8 before:-translate-x-1/2 before:rounded-full before:bg-indigo-500 before:content-[attr(before)]':
                    isActive(item.href),
                'text-red-500 hover:bg-accent/50 hover:text-accent-foreground': !isActive(item.href),
            }"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
    </nav>
</template>
