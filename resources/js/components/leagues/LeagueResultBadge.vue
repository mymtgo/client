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

/**
 * The ring around the score, drawn as a gradient behind a 2px inset.
 *
 * Every tone but the trophy is a theme token rather than a raw palette
 * colour, so the badge tracks the rest of the app. The trophy keeps its
 * gradient on purpose: it is the one result worth making loud.
 */
const tone = computed(() => {
    switch (props.classification) {
        case 'TROPHY':
            return 'from-pink-400 via-sky-300 to-blue-400';
        case 'CASH':
            return 'from-success/60 to-success text-success';
        case 'FINISH':
            return 'from-border to-border';
        case 'BRICK':
            return 'from-destructive/50 to-destructive/80 text-destructive';
        case 'LIVE':
            return 'from-primary/50 to-primary text-primary';
        default:
            return 'from-border to-border text-muted-foreground';
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
