<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Bug, Layers, LayoutDashboard, Puzzle, Swords, Trophy, Layers2Icon } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();

/** Nav targets not rebuilt yet — inert specimen hrefs until routes return. */
const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: '#' },
    { label: 'Decks', icon: Layers, href: '#' },
    { label: 'Leagues', icon: Trophy, href: '#' },
    { label: 'Opponents', icon: Swords, href: '#' },
    { label: 'Archetypes', icon: Puzzle, href: '#' },
    { label: 'Reports', icon: BarChart3, href: '#' },
    { label: 'Cards', icon: Layers2Icon, href: '#' },
];

const debugMode = computed(() => (usePage().props as Record<string, unknown>).debugMode as boolean);

const isActive = (href: string) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};
</script>

<template>
    <!-- v2 active state (decided · B): raised surface + line-2 border, foreground text. -->
    <nav class="flex shrink-0 flex-wrap items-center gap-1.5 border-b border-border bg-background px-4 py-2">
        <Link
            v-for="item in nav"
            :key="item.label"
            :href="item.href"
            prefetch="hover"
            cache-for="10s"
            class="inline-flex items-center gap-2 rounded-md border px-3.5 py-2 text-[13px] font-medium transition-colors"
            :class="{
                'border-line-2 bg-muted text-foreground': isActive(item.href),
                'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground': !isActive(item.href),
            }"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
        <Link
            v-if="debugMode"
            href="/debug/matches"
            prefetch="hover"
            cache-for="10s"
            class="inline-flex items-center gap-2 rounded-md border px-3.5 py-2 text-[13px] font-medium transition-colors"
            :class="{
                'border-line-2 bg-muted text-foreground': isActive('/debug'),
                'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground': !isActive('/debug'),
            }"
        >
            <Bug class="size-4" />
            Debug
        </Link>
    </nav>
</template>
