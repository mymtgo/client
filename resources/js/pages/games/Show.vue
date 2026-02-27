<script setup lang="ts">
import GameReplay from './partials/GameReplay.vue';
import AppLayout from '@/AppLayout.vue';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';

defineProps<{
    game: App.Data.Front.GameData & { match_id: number };
    timeline: App.Data.Front.GameTimelineData[];
}>();
</script>

<template>
    <AppLayout
        :breadcrumbs="[
            { label: 'Match', href: MatchShowController({ id: game.match_id }).url },
            { label: 'Game Replay' },
        ]"
    >
        <div class="flex flex-col gap-4 p-3 lg:p-4">
            <h1 class="text-2xl font-bold tracking-tight">Game Replay</h1>
            <GameReplay :timeline="timeline" />
        </div>
    </AppLayout>
</template>
