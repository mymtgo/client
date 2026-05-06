<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import type { OpponentData } from '@/components/leagues/OpponentScout.vue';
import OpponentScoutComponent from '@/components/leagues/OpponentScout.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    opponent: OpponentData | null;
}>();

let interval: ReturnType<typeof setInterval> | null = null;

const stopPolling = () => {
    if (interval) {
        clearInterval(interval);
        interval = null;
    }
};

onMounted(() => {
    if (props.opponent?.lastArchetype) {
        return;
    }

    interval = setInterval(() => {
        router.reload({ only: ['opponent'] });
    }, 5000);
});

watch(
    () => props.opponent?.lastArchetype,
    (archetype) => {
        if (archetype) {
            stopPolling();
        }
    },
);

onUnmounted(stopPolling);
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <OpponentScoutComponent v-if="opponent" :opponent="opponent" />
        <div v-else class="flex h-full items-center justify-center px-4 text-center text-sm text-white opacity-50">
            Waiting for match...
        </div>
    </div>
</template>
