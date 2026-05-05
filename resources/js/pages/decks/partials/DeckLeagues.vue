<script setup lang="ts">
import LeagueCard from '@/components/leagues/LeagueCard.vue';
import type { LeagueRun } from '@/types/leagues';

defineProps<{ leagues: LeagueRun[] }>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <p v-if="!leagues.length" class="py-8 text-center text-sm text-muted-foreground">No league runs found for this deck.</p>
        <LeagueCard v-for="(league, index) in leagues" :key="league.id" :league="league" hide-deck-identity :default-expanded="index === 0" />
    </div>
</template>
