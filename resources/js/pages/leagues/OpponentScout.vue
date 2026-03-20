<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import type { LiveArchetypeEstimate, OpponentData } from '@/components/leagues/OpponentScout.vue';
import OpponentScoutComponent from '@/components/leagues/OpponentScout.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    opponent: OpponentData | null;
}>();

const liveEstimate = ref<LiveArchetypeEstimate | null>(null);

let interval: ReturnType<typeof setInterval>;

onMounted(() => {
    interval = setInterval(() => {
        router.reload({ only: ['opponent'] });
    }, 5000);

    // Listen for live archetype estimates
    window.Native?.on('App\\Events\\ArchetypeEstimateUpdated', (event: any) => {
        liveEstimate.value = {
            archetypeName: event.archetypeName,
            archetypeColorIdentity: event.archetypeColorIdentity,
            confidence: event.confidence,
            cardsSeen: event.cardsSeen,
        };
    });
});

onUnmounted(() => {
    clearInterval(interval);
});

// Reset live estimate when opponent changes (new match)
watch(
    () => props.opponent?.username,
    () => {
        liveEstimate.value = null;
    },
);
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <OpponentScoutComponent v-if="opponent" :opponent="opponent" :live-estimate="liveEstimate" />
        <div v-else class="flex h-full items-center justify-center px-4 text-center text-sm text-white opacity-50">
            Waiting for match...
        </div>
    </div>
</template>
