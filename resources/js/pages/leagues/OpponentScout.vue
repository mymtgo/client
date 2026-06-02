<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import DrawOddsPanel from '@/components/leagues/DrawOddsPanel.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

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
    drawOdds: DrawOdds | null;
}>();

let interval: ReturnType<typeof setInterval> | null = null;

const stopPolling = () => {
    if (interval) {
        clearInterval(interval);
        interval = null;
    }
};

onMounted(() => {
    interval = setInterval(() => {
        router.reload({ only: ['drawOdds'] });
    }, 1000);
});

onUnmounted(stopPolling);
</script>

<template>
    <DrawOddsPanel :draw-odds="drawOdds" />
</template>
