<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import OpponentScoutComponent, { type OpponentData } from '@/components/leagues/OpponentScout.vue';
import DrawOddsPanel from '@/components/leagues/DrawOddsPanel.vue';
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

/**
 * The backend serializes `cards` as a plain array at runtime, but the generated
 * `DrawOddsData` type describes it as a keyed record (a Spatie DataCollection
 * artifact). Override that member to an array so the prop passes straight
 * through to DrawOddsPanel, which expects the same shape.
 */
type DrawOdds = Omit<App.Data.Front.DrawOddsData, 'cards'> & {
    cards: App.Data.Front.DrawOddsCardData[];
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
    <div class="flex h-screen flex-col bg-transparent text-foreground">
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
