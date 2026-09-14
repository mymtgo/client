<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import KpiBar from '@/pages/decks/partials/KpiBar.vue';
import KpiCard from '@/pages/decks/partials/KpiCard.vue';
import { computed } from 'vue';

type Matchup = {
    archetype_id: number;
    name: string;
    color_identity: string | null;
    match_winrate: number;
    match_record: string;
    matches: number;
};

const props = defineProps<{
    spread?: Matchup[];
}>();

/**
 * Fewer matches than this and a win rate is a coin flip with a number on it,
 * so those archetypes are not eligible to be called best or worst.
 */
const MIN_MATCHES = 3;

const ranked = computed(() =>
    [...(props.spread ?? [])]
        .filter((matchup) => matchup.matches >= MIN_MATCHES)
        .sort((a, b) => b.match_winrate - a.match_winrate || b.matches - a.matches),
);

const best = computed(() => ranked.value[0] ?? null);

/** Only a second archetype can be the worst one; one row cannot be both. */
const worst = computed(() => (ranked.value.length > 1 ? ranked.value[ranked.value.length - 1] : null));

/**
 * The median matchup, which needs a third archetype to exist at all. Shown
 * between the two extremes so the card reads as a spread: without it, one
 * catastrophic matchup looks the same as a deck that is bad across the board.
 */
const middle = computed(() => (ranked.value.length > 2 ? ranked.value[Math.floor(ranked.value.length / 2)] : null));
</script>

<template>
    <KpiCard label="Matchup spread" :scope="best ? `${MIN_MATCHES}+ matches` : null">
        <div v-if="best" class="flex flex-col gap-3">
            <KpiBar :rate="best.match_winrate" :record="best.match_record" tone="success">
                <template #label>
                    <span class="flex min-w-0 items-center gap-1.5">
                        <ManaSymbols :symbols="best.color_identity" class="shrink-0" />
                        <span class="truncate">{{ best.name }}</span>
                    </span>
                </template>
            </KpiBar>

            <KpiBar v-if="middle" :rate="middle.match_winrate" :record="middle.match_record" tone="muted">
                <template #label>
                    <span class="flex min-w-0 items-center gap-1.5">
                        <ManaSymbols :symbols="middle.color_identity" class="shrink-0" />
                        <span class="truncate">{{ middle.name }}</span>
                    </span>
                </template>
            </KpiBar>

            <KpiBar v-if="worst" :rate="worst.match_winrate" :record="worst.match_record" tone="destructive">
                <template #label>
                    <span class="flex min-w-0 items-center gap-1.5">
                        <ManaSymbols :symbols="worst.color_identity" class="shrink-0" />
                        <span class="truncate">{{ worst.name }}</span>
                    </span>
                </template>
            </KpiBar>

            <p v-else class="text-xs text-muted-foreground">
                Only one archetype has {{ MIN_MATCHES }} or more matches so far.
            </p>
        </div>

        <p v-else class="py-4 text-sm text-muted-foreground">
            No archetype has {{ MIN_MATCHES }} matches yet, which is the fewest that says anything.
        </p>
    </KpiCard>
</template>
