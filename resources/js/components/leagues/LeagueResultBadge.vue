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
            return 'from-pink-400 via-sky-300 to-blue-400';
        case 'CASH':
            return 'from-emerald-500 to-emerald-600';
        case 'FINISH':
            return 'from-neutral-800 to-neutral-800';
        case 'BRICK':
            return 'from-red-900 to-red-900';
        case 'LIVE':
            return 'border-sky-500/40 from-sky-100 to-sky-200 text-sky-400';
        default:
            return '';
    }
});
</script>

<template>
    <div class="relative flex shrink-0 flex-col items-center justify-center rounded-md bg-linear-to-t p-0.5" :class="tone">
        <div class="rounded-sm bg-background px-3 py-2">
            <span class="leading-none font-bold">{{ score }}</span>
        </div>
    </div>
</template>
