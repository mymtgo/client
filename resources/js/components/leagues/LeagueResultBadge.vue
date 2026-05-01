<script setup lang="ts">
import type { LeagueClassification } from '@/types/leagues';
import { Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    classification: LeagueClassification;
    wins: number;
    losses: number;
    liveRound: number | null;
}>();

const score = computed(() => `${props.wins}-${props.losses}`);

const label = computed(() => {
    if (props.classification === 'LIVE') {
        return props.liveRound ? `LIVE-R${props.liveRound}` : 'LIVE';
    }
    return props.classification;
});

const tone = computed(() => {
    switch (props.classification) {
        case 'TROPHY':
            return 'border-yellow-500/40 bg-yellow-500/10 text-yellow-400';
        case 'CASH':
            return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400';
        case 'FINISH':
            return 'border-muted-foreground/30 bg-muted/40 text-muted-foreground';
        case 'BRICK':
            return 'border-zinc-700/60 bg-zinc-900/60 text-zinc-500';
        case 'LIVE':
            return 'border-sky-500/40 bg-sky-500/10 text-sky-400';
        default:
            return '';
    }
});
</script>

<template>
    <div
        class="relative flex h-16 w-24 shrink-0 flex-col items-center justify-center rounded-md border tabular-nums"
        :class="tone"
    >
        <Trophy
            v-if="classification === 'TROPHY'"
            class="absolute -top-2 -right-2 size-4 fill-yellow-500 text-yellow-500"
        />
        <span class="text-2xl leading-none font-bold">{{ score }}</span>
    </div>
</template>
