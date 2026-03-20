<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type MatchupEntry = {
    name: string;
    winrate: number;
    wins: number;
    losses: number;
    matches: number;
};

defineProps<{
    matchupSpread: MatchupEntry[];
}>();
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">Top Matchups</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-3">
            <template v-if="matchupSpread.length > 0">
                <div
                    v-for="entry in matchupSpread"
                    :key="entry.name"
                    class="flex flex-col gap-1"
                >
                    <div class="flex items-center justify-between text-sm">
                        <span class="truncate font-medium">{{ entry.name }}</span>
                        <div class="flex items-center gap-2 shrink-0">
                            <span
                                class="font-bold tabular-nums"
                                :class="entry.winrate >= 50 ? 'text-success' : 'text-destructive'"
                            >
                                {{ entry.winrate }}%
                            </span>
                            <span class="text-xs text-muted-foreground tabular-nums">
                                {{ entry.wins }}W–{{ entry.losses }}L
                            </span>
                        </div>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="entry.winrate >= 50 ? 'bg-success' : 'bg-destructive'"
                            :style="{ width: `${entry.winrate}%` }"
                        />
                    </div>
                </div>
            </template>

            <div v-else class="py-8 text-center text-sm text-muted-foreground">
                Play some matches to see matchup data
            </div>
        </CardContent>
    </Card>
</template>
