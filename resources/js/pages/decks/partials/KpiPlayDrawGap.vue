<script setup lang="ts">
import KpiBar from '@/pages/decks/partials/KpiBar.vue';
import KpiCard from '@/pages/decks/partials/KpiCard.vue';
import { computed } from 'vue';

const props = defineProps<{
    otpRate: number;
    gamesOtpWon: number;
    gamesOtpLost: number;
    otdRate: number;
    gamesOtdWon: number;
    gamesOtdLost: number;
    /** Games whose log said who was on the play. */
    playDrawGames: number;
    /** Every game in range, logged or not, for the scope note. */
    totalGames: number;
}>();

const gap = computed(() => props.otpRate - props.otdRate);
</script>

<template>
    <KpiCard label="Play / draw gap" :scope="`${playDrawGames} of ${totalGames} games`">
        <template v-if="playDrawGames > 0">
            <div class="flex items-baseline gap-2">
                <span class="text-4xl font-bold tabular-nums text-warning">{{ gap }}</span>
                <span class="text-sm text-muted-foreground">pts</span>
            </div>

            <div class="flex flex-col gap-2.5">
                <KpiBar
                    label="On the play"
                    :rate="otpRate"
                    :record="`${gamesOtpWon}–${gamesOtpLost}`"
                    :tone="otpRate >= 50 ? 'success' : 'destructive'"
                />
                <KpiBar
                    label="On the draw"
                    :rate="otdRate"
                    :record="`${gamesOtdWon}–${gamesOtdLost}`"
                    :tone="otdRate >= 50 ? 'success' : 'destructive'"
                />
            </div>
        </template>

        <p v-else class="py-4 text-sm text-muted-foreground">
            Who was on the play is only known from a game log. None of the {{ totalGames }} games in this range has one.
        </p>
    </KpiCard>
</template>
