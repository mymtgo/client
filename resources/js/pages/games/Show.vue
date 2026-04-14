<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import GameReplay from './partials/GameReplay.vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    game: App.Data.Front.GameData & { match_id: number };
    timeline: App.Data.Front.GameTimelineData[];
    gameLog: Array<{ timestamp: string; message: string }>;
}>();
</script>

<template>
    <div class="flex h-full flex-col gap-4 overflow-y-auto p-3 lg:p-4">
        <h1 class="text-lg font-bold tracking-tight">Game Replay</h1>
        <GameReplay :timeline="timeline" :game-log="gameLog" />
    </div>
</template>
