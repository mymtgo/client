<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

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
        <Card class="gap-2">
            <CardHeader>
                <CardDescription>Total Matches</CardDescription>
            </CardHeader>
            <CardContent class="text-2xl font-semibold">
                {{ matchesWon }}-{{ matchesLost }} <span class="text-muted-foreground text-sm font-normal">({{ matchesWon + matchesLost }})</span>
            </CardContent>
        </Card>
        <Card class="gap-2">
            <CardHeader>
                <CardDescription>Match Winrate</CardDescription>
            </CardHeader>
            <CardContent class="text-2xl font-semibold">{{ matchWinrate }}%</CardContent>
        </Card>
        <Card class="gap-2">
            <CardHeader>
                <CardDescription>Total Games</CardDescription>
            </CardHeader>
            <CardContent class="text-2xl font-semibold">
                {{ gamesWon }}-{{ gamesLost }} <span class="text-muted-foreground text-sm font-normal">({{ gamesWon + gamesLost }})</span>
            </CardContent>
        </Card>
        <Card class="gap-2">
            <CardHeader>
                <CardDescription>Game Winrate</CardDescription>
            </CardHeader>
            <CardContent class="text-2xl font-semibold">{{ gameWinrate }}%</CardContent>
        </Card>
    </div>
</template>
