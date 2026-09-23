<script setup lang="ts">
import OverlayLayout from '@/layouts/OverlayLayout.vue';
import { show } from '@/routes/games';
import { Link } from '@inertiajs/vue3';
import { ReplayViewer, type ReplayFrame, type ReplayLogEntry, type ReplayMatchGame } from '@mymtgo/replay';

defineOptions({ layout: OverlayLayout });

defineProps<{
    game: App.Data.Front.GameData & { match_id: number; won: boolean | null };
    timeline: ReplayFrame[];
    gameLog: ReplayLogEntry[];
    matchGames: ReplayMatchGame[];
}>();

const gameHref = (id: number) => show(id).url;
</script>

<template>
    <ReplayViewer
        :frames="timeline"
        :log="gameLog"
        :won="game.won"
        :game-id="game.id"
        :match-games="matchGames"
        :game-href="gameHref"
        :link-component="Link"
    />
</template>
