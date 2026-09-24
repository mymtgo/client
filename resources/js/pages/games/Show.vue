<script setup lang="ts">
import ReplayShareButton from '@/components/replay-share/ReplayShareButton.vue';
import type { ReplayShareState } from '@/components/replay-share/types';
import OverlayLayout from '@/layouts/OverlayLayout.vue';
import { show } from '@/routes/games';
import { Link } from '@inertiajs/vue3';
import { gameSideboard, ReplayViewer, type ReplayFrame, type ReplayLogEntry, type ReplayMatchGame, type ReplaySideboardEntry } from '@mymtgo/replay';
import { computed } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    game: App.Data.Front.GameData & { match_id: number; won: boolean | null };
    timeline: ReplayFrame[];
    gameLog: ReplayLogEntry[];
    matchGames: ReplayMatchGame[];
    share: ReplayShareState;
    /** Your sideboard as this game began; null when none was recorded. */
    sideboard: ReplaySideboardEntry[] | null;
    /** Your sideboard as the previous recorded game began; null for a first game. */
    previousSideboard: { game: number; sideboard: ReplaySideboardEntry[] } | null;
}>();

const previous = computed(() =>
    props.previousSideboard ? { game: props.previousSideboard.game, cards: gameSideboard([], props.previousSideboard.sideboard) ?? [] } : null,
);

const gameHref = (id: number) => show(id).url;
</script>

<template>
    <ReplayViewer
        :frames="timeline"
        :log="gameLog"
        :won="game.won"
        :game-id="game.id"
        :match-games="matchGames"
        :previous-sideboard="previous"
        :sideboard="sideboard"
        :game-href="gameHref"
        :link-component="Link"
    >
        <template #actions>
            <ReplayShareButton :game-id="game.id" :share="share" />
        </template>
    </ReplayViewer>
</template>
