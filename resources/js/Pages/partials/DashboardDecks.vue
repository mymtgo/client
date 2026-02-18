<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TrendingUp, TrendingDown } from 'lucide-vue-next';

// FAKE DATA — replace with props from backend
const bestDeck = {
    id: 1,
    name: 'Boros Energy',
    format: 'Standard',
    winRate: 68,
    matchCount: 34,
};

const worstDeck = {
    id: 4,
    name: 'Azorius Control',
    format: 'Pioneer',
    winRate: 36,
    matchCount: 14,
};
</script>

<template>
    <div class="grid grid-cols-2 gap-4">
        <!-- Best performing deck -->
        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium text-muted-foreground uppercase tracking-wide">
                    <TrendingUp class="size-4 text-win" />
                    Best Performing
                </CardTitle>
            </CardHeader>
            <CardContent class="flex items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <span class="text-lg font-semibold leading-tight">{{ bestDeck.name }}</span>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ bestDeck.format }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ bestDeck.matchCount }} matches</span>
                    </div>
                </div>
                <span class="text-4xl font-bold tabular-nums text-win">{{ bestDeck.winRate }}%</span>
            </CardContent>
        </Card>

        <!-- Worst performing deck -->
        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium text-muted-foreground uppercase tracking-wide">
                    <TrendingDown class="size-4 text-destructive" />
                    Worst Performing
                </CardTitle>
            </CardHeader>
            <CardContent class="flex items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <span class="text-lg font-semibold leading-tight">{{ worstDeck.name }}</span>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ worstDeck.format }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ worstDeck.matchCount }} matches</span>
                    </div>
                </div>
                <span class="text-4xl font-bold tabular-nums text-destructive">{{ worstDeck.winRate }}%</span>
            </CardContent>
        </Card>
    </div>
</template>
