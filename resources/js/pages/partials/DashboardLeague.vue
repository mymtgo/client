<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Trophy } from 'lucide-vue-next';
import PhantomBadge from '@/components/leagues/PhantomBadge.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';

type League = {
    name: string;
    format: string;
    phantom: boolean;
    isActive: boolean;
    isTrophy: boolean;
    deckName: string | null;
    results: ('W' | 'L' | null)[];
    wins: number;
    losses: number;
    matchesRemaining: number;
};

defineProps<{
    league: League | null;
}>();
</script>

<template>
    <Card v-if="league">
        <CardHeader class="pb-2">
            <div class="flex items-center justify-between">
                <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">
                    {{ league.isActive ? 'Active League Run' : 'Last League Run' }}
                </CardTitle>
                <Trophy v-if="league.isTrophy" class="size-4 text-yellow-400" />
            </div>
        </CardHeader>

        <CardContent class="flex items-center justify-between gap-6">
            <!-- Left: deck info + record -->
            <div class="flex flex-col gap-1">
                <span class="text-lg font-semibold leading-tight">{{ league.deckName ?? league.name }}</span>
                <div class="flex items-center gap-2">
                    <Badge variant="outline">{{ league.format }}</Badge>
                    <PhantomBadge v-if="league.phantom" />
                    <span class="text-sm text-muted-foreground">
                        <span class="font-medium">{{ league.wins }}W</span>
                        <span class="mx-0.5">–</span>
                        <span class="text-destructive font-medium">{{ league.losses }}L</span>
                        <span v-if="league.isActive" class="ml-1 text-muted-foreground">· {{ league.matchesRemaining }} remaining</span>
                    </span>
                </div>
            </div>

            <!-- Right: pip indicators -->
            <div class="flex items-center gap-1.5">
                <template v-for="(result, i) in league.results" :key="i">
                    <ResultBadge :won="result === 'W'" />
                </template>
            </div>
        </CardContent>
    </Card>
</template>
