<script setup lang="ts">
import type { OverlayState } from '@/components/leagues/LeagueOverlayCard.vue';
import LeagueOverlayCard from '@/components/leagues/LeagueOverlayCard.vue';
import OverlayLayout from '@/layouts/OverlayLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    state: OverlayState;
}>();

/**
 * The poll is only a fallback now that the pipeline pushes LeagueOverlayChanged.
 * `keepAlive` stops Inertia cutting it to every tenth tick (50s) whenever
 * Chromium reports the window hidden, which is how a streamer's overlay sits
 * behind the game or on a captured screen.
 */
usePoll(5000, { only: ['state'] }, { keepAlive: true });

/**
 * One pipeline tick can create a game, record its result and complete the
 * match, each firing the event. Collapse the burst into one reload.
 */
const reloadState = useDebounceFn(() => router.reload({ only: ['state'] }), 150);

onMounted(() => {
    window.Native?.on('App\\Events\\LeagueOverlayChanged', reloadState);
});
</script>

<template>
    <!-- p-4 matches OpenOverlayWindow::PADDING: transparent room for the card glow, which stays within 16px. -->
    <div class="h-screen p-4" style="-webkit-app-region: drag">
        <LeagueOverlayCard :state="state" />
    </div>
</template>
