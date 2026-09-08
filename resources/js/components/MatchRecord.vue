<script setup lang="ts">
import type { MatchRecordStyle } from '@/lib/matchRecord';

withDefaults(
    defineProps<{
        record: App.Data.Front.MatchRecordData;
        format?: MatchRecordStyle;
        size?: 'xs' | 'sm' | 'base';
        muted?: boolean;
        showWinrate?: boolean;
    }>(),
    { format: 'letters', size: 'xs', muted: true, showWinrate: false },
);

const sizeClass = { xs: 'text-xs', sm: 'text-sm', base: 'text-base' };
</script>

<template>
    <span class="tabular-nums" :class="[sizeClass[size], muted ? 'text-muted-foreground' : '']">
        <template v-if="format === 'letters'">
            <span :class="muted ? 'text-foreground' : ''">{{ record.wins }}W</span>
            <span class="mx-0.5">-</span>
            <span class="text-destructive">{{ record.losses }}L</span>
            <template v-if="record.draws > 0">
                <span class="mx-0.5">-</span>
                <span>{{ record.draws }}D</span>
            </template>
        </template>
        <template v-else>{{ record.label }}</template>
        <template v-if="showWinrate"> · {{ record.winrate }}%</template>
    </span>
</template>
