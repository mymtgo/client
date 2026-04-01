<script setup lang="ts">
import DeckSidebar from '@/components/decks/DeckSidebar.vue';
import type { VersionStats } from '@/types/decks';

defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe?: string;
}>();
</script>

<template>
    <div class="flex min-h-0 flex-1">
        <div class="w-56 shrink-0">
            <DeckSidebar
                :deck="deck"
                :versions="versions"
                :current-version-id="currentVersionId"
                :trophies="trophies"
                :current-page="currentPage"
                :timeframe="timeframe"
            />
        </div>
        <div class="flex min-h-0 flex-1 flex-col border-l border-white/5 overflow-y-auto">
            <slot />
        </div>
    </div>
</template>
