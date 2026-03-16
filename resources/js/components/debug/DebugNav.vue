<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const tabs = [
    { label: 'Matches', href: '/debug/matches' },
    { label: 'Games', href: '/debug/games' },
    { label: 'Log Events', href: '/debug/log-events' },
    { label: 'Decks', href: '/debug/decks' },
    { label: 'Deck Versions', href: '/debug/deck-versions' },
    { label: 'Log Cursors', href: '/debug/log-cursors' },
    { label: 'Pipeline Log', href: '/debug/pipeline-log' },
];

const isActive = (href: string) => page.url.startsWith(href);
</script>

<template>
    <div class="flex gap-1 border-b border-border/60 bg-muted/20 px-4 py-2">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="{
                'bg-primary/10 text-primary': isActive(tab.href),
                'text-muted-foreground hover:text-foreground hover:bg-muted/40': !isActive(tab.href),
            }"
        >
            {{ tab.label }}
        </Link>
    </div>
</template>
