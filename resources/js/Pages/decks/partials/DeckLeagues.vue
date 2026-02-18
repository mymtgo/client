<script setup lang="ts">
import { Table, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import DeckMatches from '@/Pages/decks/partials/DeckMatches.vue';
import MatchesTable from '@/components/matches/MatchesTable.vue';

defineProps<{
    leagues: App.Data.Front.LeagueData;
    archetypes: App.Data.Front.ArchetypeData[];
}>();
</script>

<template>
    <div>
        <Card class="gap-4 overflow-hidden" v-for="(league, idx) in leagues" :key="`league_${idx}`">
            <CardHeader>
                {{ league.name }}
            </CardHeader>
            <CardContent class="px-0">
                <MatchesTable :matches="league.matches" :archetypes="archetypes" />
            </CardContent>
        </Card>
    </div>
</template>
