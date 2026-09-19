<script setup lang="ts">
import MatchesTable from '@/components/matches/MatchesTable.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    matches: App.Data.Front.MatchData[];
}>();
</script>

<template>
    <Card class="overflow-hidden">
        <CardHeader>
            <CardTitle class="text-sm font-semibold tracking-wider text-muted-foreground uppercase">Recent Matches</CardTitle>
        </CardHeader>
        <CardContent class="overflow-x-auto px-0 pt-0">
            <p v-if="!matches.length" class="px-6 pb-4 text-sm text-muted-foreground">No matches in this timeframe</p>
            <MatchesTable v-else :matches="matches" show-deck />
        </CardContent>
    </Card>
</template>
