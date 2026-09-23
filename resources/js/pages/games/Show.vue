<script setup lang="ts">
import ReplayViewer from '@/components/replay/ReplayViewer.vue';
import type { ReplayFrame, ReplayLogEntry, ReplayMatchGame } from '@/components/replay/types';
import OverlayLayout from '@/layouts/OverlayLayout.vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    game: App.Data.Front.GameData & { match_id: number; won: boolean | null };
    timeline: ReplayFrame[];
    gameLog: ReplayLogEntry[];
    matchGames: ReplayMatchGame[];
}>();
</script>

<template>
    <ReplayViewer :frames="timeline" :log="gameLog" :won="game.won" :game-id="game.id" :match-games="matchGames" />
</template>
