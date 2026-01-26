<script setup lang="ts">
import { Table, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import DeckMatches from '@/Pages/decks/partials/DeckMatches.vue';
import MatchesTable from '@/components/matches/MatchesTable.vue';

defineProps<{
    leagues: App.Data.Front.LeagueData;
}>();
</script>

<template>
    <div>
        <Card class="gap-4 overflow-hidden" v-for="(league, idx) in leagues" :key="`league_${idx}`">
            <CardHeader>
                {{ league.name }}
            </CardHeader>
            <CardContent class="px-0">
                <MatchesTable :matches="league.matches" />
            </CardContent>
        </Card>
    </div>
</template>

<style scoped></style>
