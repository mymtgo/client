<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    league: LeagueData | null;
}>();

// Fallback poll for missed events; primary updates are event-driven below.
usePoll(5000, { only: ['league'] });

onMounted(() => {
    // Single overlay window that never navigates — a direct listener is safe
    // here (no unsubscribe exists on Native.on; see useReloadOnMatchCompleted).
    window.Native?.on('App\\Events\\GameResultRecorded', () => {
        router.reload({ only: ['league'] });
    });
});
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <LeagueTracker :league="league" />
    </div>
</template>
