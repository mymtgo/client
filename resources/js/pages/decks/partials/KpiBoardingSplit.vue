<script setup lang="ts">
import KpiBar from '@/pages/decks/partials/KpiBar.vue';
import KpiCard from '@/pages/decks/partials/KpiCard.vue';
import { computed } from 'vue';

const props = defineProps<{
    split: {
        gameOneWon: number;
        gameOneLost: number;
        gameOneRate: number;
        postBoardWon: number;
        postBoardLost: number;
        postBoardRate: number;
        delta: number;
        games: number;
    };
}>();

const tone = computed(() => {
    if (props.split.delta > 0) return 'text-success';
    if (props.split.delta < 0) return 'text-destructive';
    return '';
});
</script>

<template>
    <KpiCard label="Game 1 vs post-board" :scope="`${split.games} games`">
        <template v-if="split.games > 0">
            <div class="flex items-baseline gap-2">
                <span class="text-4xl font-bold tabular-nums" :class="tone">
                    {{ split.delta > 0 ? '+' : '' }}{{ split.delta }}
                </span>
                <span class="text-sm text-muted-foreground">pts after boarding</span>
            </div>

            <div class="flex flex-col gap-2.5">
                <KpiBar
                    label="Game 1"
                    :rate="split.gameOneRate"
                    :record="`${split.gameOneWon}–${split.gameOneLost}`"
                    :tone="split.gameOneRate >= 50 ? 'success' : 'destructive'"
                />
                <KpiBar
                    label="Games 2–3"
                    :rate="split.postBoardRate"
                    :record="`${split.postBoardWon}–${split.postBoardLost}`"
                    :tone="split.postBoardRate >= 50 ? 'success' : 'destructive'"
                />
            </div>
        </template>

        <p v-else class="py-4 text-sm text-muted-foreground">
            Which game was which comes from a game log. None of the matches in this range has one.
        </p>
    </KpiCard>
</template>
