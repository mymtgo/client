<script setup lang="ts">
import TournamentCard from '@/components/tournaments/TournamentCard.vue';
import type { TournamentRun } from '@/types/tournaments';

defineProps<{
    tournaments: TournamentRun[];
    archetypes?: App.Data.Front.ArchetypeData[];
}>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <p
            v-if="!tournaments.length"
            class="py-8 text-center text-sm text-muted-foreground"
        >
            No tournaments found for this deck.
        </p>
        <TournamentCard
            v-for="(tournament, index) in tournaments"
            :key="tournament.id"
            :tournament="tournament"
            :archetypes="archetypes ?? []"
            :default-expanded="index === 0"
            hide-deck-identity
        />
    </div>
</template>
