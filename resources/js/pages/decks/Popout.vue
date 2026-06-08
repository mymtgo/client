<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import DrawOddsPanel from '@/components/decks/DrawOddsPanel.vue';
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

/**
 * The backend serializes `cards` as a plain array at runtime, but the generated
 * `DrawOddsData` type describes it as a keyed record (a Spatie DataCollection
 * artifact). Override that member to an array so the prop passes straight
 * through to DrawOddsPanel.
 */
type DrawOdds = Omit<App.Data.Front.DrawOddsData, 'cards'> & {
    cards: App.Data.Front.DrawOddsCardData[];
};

defineProps<{
    drawOdds: DrawOdds | null;
}>();

onMounted(() => {
    window.Native?.on('App\\Events\\GameCardsSnapshotChanged', () => {
        router.reload({ only: ['drawOdds'] });
    });
});
</script>

<template>
    <div class="flex h-screen flex-col bg-background text-foreground">
        <DrawOddsPanel :draw-odds="drawOdds" />
    </div>
</template>
