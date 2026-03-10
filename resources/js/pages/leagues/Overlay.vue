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
    opponentEnabled: boolean;
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
            <div v-else-if="opponentEnabled" class="px-4 py-2 text-center text-sm opacity-50" style="color: #ffffff; background-color: #1a1a1a">
                Opponent will appear when match starts
            </div>
        </div>
    </div>
</template>
