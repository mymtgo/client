<script setup lang="ts">
import GameReplay from './partials/GameReplay.vue';
import AppLayout from '@/AppLayout.vue';

defineProps<{
    game: App.Data.Front.GameData;
    timeline: App.Data.Front.GameTimelineData[];
}>();
</script>

<template>
    <AppLayout>
        <h1 class="mb-6 text-2xl font-bold">Game Replay</h1>

        <GameReplay :timeline="timeline" />
    </AppLayout>
</template>
