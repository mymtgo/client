<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import type { WidgetConfig } from '@/pages/partials/dashboardWidgets';

type FormatRow = {
    code: string;
    label: string;
    record: App.Data.Front.MatchRecordData;
    gamesWon: number;
    gamesLost: number;
    gameWinrate: number;
};

defineProps<{ data: FormatRow[]; config: WidgetConfig }>();
</script>

<template>
    <Card>
        <CardContent>
            <div v-if="data.length === 0" class="py-6 text-center text-sm text-muted-foreground">Play a constructed match to see formats here</div>
            <div v-else class="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3">
                <div v-for="row in data" :key="row.code" class="flex flex-col gap-1 rounded-lg border p-3">
                    <span class="truncate text-sm font-medium">{{ row.label }}</span>
                    <span class="text-2xl font-bold tabular-nums" :class="row.record.winrate < 50 ? 'text-destructive' : ''">
                        {{ row.record.winrate }}%
                    </span>
                    <span class="text-xs text-muted-foreground/60 tabular-nums">{{ row.record.label }}</span>
                    <span class="text-xs text-muted-foreground/60 tabular-nums"
                        >Games {{ row.gameWinrate }}% ({{ row.gamesWon }}-{{ row.gamesLost }})</span
                    >
                </div>
            </div>
        </CardContent>
    </Card>
</template>
