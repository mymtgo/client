<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import type { OpponentData } from '@/components/leagues/OpponentScout.vue';
import OpponentScout from '@/components/leagues/OpponentScout.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    league: LeagueData | null;
    opponent: OpponentData | null;
}>();

let interval: ReturnType<typeof setInterval>;

onMounted(() => {
    interval = setInterval(() => {
        router.reload({ only: ['league', 'opponent'] });
    }, 5000);
});

onUnmounted(() => {
    clearInterval(interval);
});
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <div class="flex h-full flex-col">
            <LeagueTracker :league="league" />
            <OpponentScout v-if="opponent" :opponent="opponent" />
        </div>
    </div>
</template>
