<script setup lang="ts">
import { ArrowLeft, ArrowRight, Check } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The running in/out tally for a sideboard plan, and whether it leaves a legal
 * board. Pinned above the two card columns so the counts stay with the steppers
 * being clicked rather than in a header the player has scrolled past.
 *
 * The threshold is the sideboard, not the maindeck: a deck may run more than 60
 * cards, but the board has to come back to the size it started at, so a plan is
 * only finished when everything brought in has been paid for by something cut.
 */
const props = defineProps<{
    totalIn: number;
    totalOut: number;
    /** Copies in the deck's sideboard, which the plan has to return to. */
    sideboardSize: number;
}>();

/** Cards cut from the maindeck go back to the board, cards brought in leave it. */
const resulting = computed(() => props.sideboardSize - props.totalIn + props.totalOut);

const shortfall = computed(() => props.sideboardSize - resulting.value);

const hint = computed(() => {
    if (shortfall.value > 0) return `cut ${shortfall.value} more`;
    if (shortfall.value < 0) return `bring in ${-shortfall.value} more`;
    return null;
});
</script>

<template>
    <div
        class="flex items-center gap-2.5 rounded-full border px-3 py-1.5 shadow-sm backdrop-blur"
        :class="hint ? 'border-amber-500/40 bg-amber-500/10' : 'border-border bg-background/95'"
    >
        <span class="flex items-center gap-1.5">
            <span class="text-sm leading-none font-semibold tabular-nums">{{ totalIn }}</span>
            <span class="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">in</span>
            <ArrowRight class="size-3 shrink-0 text-muted-foreground/50" />
        </span>
        <span class="flex items-center gap-1.5">
            <ArrowLeft class="size-3 shrink-0 text-muted-foreground/50" />
            <span class="text-sm leading-none font-semibold tabular-nums">{{ totalOut }}</span>
            <span class="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">out</span>
        </span>

        <span class="h-3.5 w-px shrink-0" :class="hint ? 'bg-amber-500/30' : 'bg-border'"></span>

        <span
            class="flex items-center gap-1.5 text-[10px] font-semibold tracking-wider uppercase tabular-nums"
            :class="hint ? 'text-amber-300' : 'text-muted-foreground'"
        >
            board {{ resulting }} / {{ sideboardSize }}
            <template v-if="hint">· {{ hint }}</template>
            <Check v-else class="size-3" aria-label="Balanced" />
        </span>
    </div>
</template>
