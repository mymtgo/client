<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import OpponentScoutComponent, { type OpponentData } from '@/components/leagues/OpponentScout.vue';
import DrawOddsPanel from '@/components/leagues/DrawOddsPanel.vue';
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

/**
 * The backend serializes `cards` and `topFive` as plain arrays at runtime, but
 * the generated `DrawOddsData` type describes them as keyed records (a Spatie
 * DataCollection artifact). Override those two members to arrays so the prop
 * passes straight through to DrawOddsPanel, which expects the same shape.
 */
type DrawOdds = Omit<App.Data.Front.DrawOddsData, 'cards' | 'topFive'> & {
    cards: App.Data.Front.DrawOddsCardData[];
    topFive: App.Data.Front.DrawOddsTypeData[];
};

defineProps<{
    opponent: OpponentData | null;
    drawOdds: DrawOdds | null;
}>();

onMounted(() => {
    window.Native?.on('App\\Events\\GameCardsSnapshotChanged', () => {
        router.reload({ only: ['opponent', 'drawOdds'] });
    });
});
</script>

<template>
    <div class="flex h-screen flex-col bg-background text-foreground">
        <!--
            Opponent archetype block. Only rendered when an opponent is known so
            the divider/empty space collapses while we wait for a match. Acts as
            the drag handle for the frameless overlay window.
        -->
        <div v-if="opponent" class="shrink-0 border-b border-border" style="-webkit-app-region: drag">
            <OpponentScoutComponent :opponent="opponent" />
        </div>

        <!-- Live draw-odds decklist fills the remaining height. -->
        <div class="min-h-0 flex-1">
            <DrawOddsPanel :draw-odds="drawOdds" />
        </div>
    </div>
</template>
