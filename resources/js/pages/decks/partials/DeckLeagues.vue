<script setup lang="ts">
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import MatchesTable from '@/components/matches/MatchesTable.vue';
import PhantomBadge from '@/components/leagues/PhantomBadge.vue';

defineProps<{
    leagues: App.Data.Front.LeagueData[];
    archetypes: App.Data.Front.ArchetypeData[];
}>();
</script>

<template>
    <div class="space-y-4">
        <Card class="gap-0 p-0 overflow-hidden" v-for="(league, idx) in leagues" :key="`league_${idx}`">
            <CardHeader class="flex items-center p-2 justify-between">
                <span class="text-sm">{{ league.name }}</span>
                <PhantomBadge v-if="league.phantom" />
            </CardHeader>
            <CardContent class="px-0">
                <MatchesTable :matches="league.matches" :archetypes="archetypes" />
            </CardContent>
        </Card>
    </div>
</template>
