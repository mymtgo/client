<script setup lang="ts">
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { WidgetConfig } from '@/pages/partials/dashboardWidgets';
import { Trophy } from 'lucide-vue-next';

type LatestLeague = {
    id: number;
    results: ('W' | 'L' | null)[];
    classification: string;
    startedAtHuman: string | null;
};

type DeckStats = {
    deck: { id: number; name: string; format: string; colorIdentity: string | null; coverArt: string | null };
    matchRecord: App.Data.Front.MatchRecordData;
    gameWinrate: number;
    gamesWon: number;
    gamesLost: number;
    latestLeague: LatestLeague | null;
};

defineProps<{ data: DeckStats | null; config: WidgetConfig }>();
</script>

<template>
    <Card class="h-full overflow-hidden">
        <CardHeader class="pb-2">
            <div class="flex items-center gap-3">
                <img v-if="data?.deck.coverArt" :src="data.deck.coverArt" alt="" class="size-10 shrink-0 rounded-md object-cover" />
                <div class="flex min-w-0 items-center gap-2">
                    <CardTitle class="truncate text-base leading-tight font-semibold">{{ data?.deck.name ?? 'Deck' }}</CardTitle>
                    <Badge v-if="data" variant="outline">{{ data.deck.format }}</Badge>
                </div>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="!data" class="py-6 text-center text-sm text-muted-foreground">This deck is no longer available.</div>
            <div v-else-if="data.matchRecord.total === 0" class="py-6 text-center text-sm text-muted-foreground">No matches in this period</div>
            <div v-else class="grid grid-cols-2 gap-3">
                <div class="flex flex-col">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Matches</span>
                    <span class="text-2xl font-bold tabular-nums" :class="data.matchRecord.winrate < 50 ? 'text-destructive' : ''">
                        {{ data.matchRecord.winrate }}%
                    </span>
                    <span class="text-xs text-muted-foreground/60 tabular-nums">{{ data.matchRecord.label }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Games</span>
                    <span class="text-2xl font-bold tabular-nums" :class="data.gameWinrate < 50 ? 'text-destructive' : ''">
                        {{ data.gameWinrate }}%
                    </span>
                    <span class="text-xs text-muted-foreground/60 tabular-nums">{{ data.gamesWon }}W - {{ data.gamesLost }}L</span>
                </div>
                <div class="col-span-2 flex flex-wrap items-center gap-2">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Last league</span>
                    <template v-if="data.latestLeague">
                        <div class="flex items-center gap-1.5">
                            <template v-for="(result, i) in data.latestLeague.results" :key="i">
                                <div v-if="result === null" class="h-2 w-2 rounded-full border border-muted-foreground/40" />
                                <ResultBadge v-else :won="result === 'W'" />
                            </template>
                            <Trophy v-if="data.latestLeague.classification === 'TROPHY'" class="size-3.5 text-yellow-400" />
                        </div>
                        <span class="text-xs text-muted-foreground/60">{{ data.latestLeague.startedAtHuman }}</span>
                    </template>
                    <span v-else class="text-xs text-muted-foreground/60">None yet</span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
