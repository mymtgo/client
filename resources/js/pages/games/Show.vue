<script setup lang="ts">
import GameReplay from './partials/GameReplay.vue';
import AppLayout from '@/AppLayout.vue';
import BackLink from '@/components/BackLink.vue';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';

defineProps<{
    game: App.Data.Front.GameData & { match_id: number };
    timeline: App.Data.Front.GameTimelineData[];
}>();
</script>

<template>
    <AppLayout title="Game Replay">
        <div class="flex flex-col gap-4 p-3 lg:p-4">
            <BackLink
                :href="MatchShowController(game.match_id).url"
                label="Back to match"
            />
            <h1 class="text-2xl font-bold tracking-tight">Game Replay</h1>
            <GameReplay :timeline="timeline" />
        </div>
    </AppLayout>
</template>
