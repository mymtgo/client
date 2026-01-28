<script setup lang="ts">
import { Card, CardContent, CardHeader } from '@/components/ui/card';

defineProps<{
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
}>();
</script>

<template>
    <div class="grid grid-cols-4 gap-4">
        <Card class="gap-0">
            <CardHeader>Total Matches</CardHeader>
            <CardContent class="text-2xl">
                {{ matchesWon }}-{{ matchesLost }} <span class="text-white/40">({{ matchesWon + matchesLost }})</span>
            </CardContent>
        </Card>
        <Card class="gap-0">
            <CardHeader>Match Winrate</CardHeader>
            <CardContent class="text-2xl"> {{ matchWinrate }}% </CardContent>
        </Card>
        <Card class="gap-0">
            <CardHeader>Total Games</CardHeader>
            <CardContent class="text-2xl">
                {{ gamesWon }}-{{ gamesLost }} <span class="text-white/40">({{ gamesWon + gamesLost }})</span>
            </CardContent>
        </Card>
        <Card class="gap-0">
            <CardHeader>Game Winrate</CardHeader>
            <CardContent class="text-2xl"> {{ gameWinrate }}% </CardContent>
        </Card>
    </div>
</template>
