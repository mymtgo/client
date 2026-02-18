<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import MatchGame from '@/Pages/matches/partials/MatchGame.vue';
import AppLayout from '@/AppLayout.vue';

defineProps<{
    cards: App.Data.Front.CardData;
    match: App.Data.Front.MatchData;
}>();
</script>

<template>
    <AppLayout>
        <Tabs default-value="game_1">
            <TabsList>
                <TabsTrigger :value="`game_${idx + 1}`" v-for="(game, idx) in match.games" :key="`game_${game.id}`"> Game {{ idx + 1 }} </TabsTrigger>
            </TabsList>
            <TabsContent :value="`game_${idx + 1}`" v-for="(game, idx) in match.games" :key="`game_${game.id}`">
                <MatchGame :game="game" />
            </TabsContent>
        </Tabs>
    </AppLayout>
</template>
