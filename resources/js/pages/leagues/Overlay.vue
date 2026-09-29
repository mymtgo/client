<script setup lang="ts">
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import OverlayLayout from '@/layouts/OverlayLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    league: LeagueData | null;
}>();

/**
 * The poll is only a fallback now that the pipeline pushes LeagueOverlayChanged.
 * `keepAlive` stops Inertia cutting it to every tenth tick (50s) whenever
 * Chromium reports the window hidden, which is how a streamer's overlay sits
 * behind the game or on a captured screen.
 */
usePoll(5000, { only: ['league'] }, { keepAlive: true });

/**
 * One pipeline tick can create a game, record its result and complete the
 * match, each firing the event. Collapse the burst into one reload.
 */
const reloadLeague = useDebounceFn(() => router.reload({ only: ['league'] }), 150);

onMounted(() => {
    window.Native?.on('App\\Events\\LeagueOverlayChanged', reloadLeague);
});
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <LeagueTracker :league="league" />
    </div>
</template>
