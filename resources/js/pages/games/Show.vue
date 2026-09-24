<script setup lang="ts">
import ReplayShareButton from '@/components/replay-share/ReplayShareButton.vue';
import type { ReplayShareState } from '@/components/replay-share/types';
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
    share: ReplayShareState;
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
    >
        <template #actions>
            <ReplayShareButton :game-id="game.id" :share="share" />
        </template>
    </ReplayViewer>
</template>
