<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    league: LeagueData | null;
}>();

let interval: ReturnType<typeof setInterval>;

onMounted(() => {
    interval = setInterval(() => {
        router.reload({ only: ['league'] });
    }, 5000);
});

onUnmounted(() => {
    clearInterval(interval);
});
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <LeagueTracker
            :league="league"
            class="h-full"
        />
    </div>
</template>
