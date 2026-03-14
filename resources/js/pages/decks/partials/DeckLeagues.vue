<script setup lang="ts">
import LeagueTable from '@/components/leagues/LeagueTable.vue';

type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string | null;
    opponentArchetype: string | null;
    games: string;
    startedAt: string;
};

type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string } | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    state: 'active' | 'complete' | 'partial';
    matches: LeagueMatch[];
};

defineProps<{
    leagues: LeagueRun[];
}>();
</script>

<template>
    <div class="space-y-3">
        <p v-if="!leagues.length" class="py-8 text-center text-sm text-muted-foreground">
            No league runs found for this deck.
        </p>
        <LeagueTable v-for="league in leagues" :key="league.id" :league="league" />
    </div>
</template>
