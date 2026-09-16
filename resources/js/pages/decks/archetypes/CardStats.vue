<script setup lang="ts">
import ArchetypeCardStatsController from '@/actions/App/Http/Controllers/Decks/Archetypes/CardStatsController';
import CardStatsView from '@/components/cards/CardStatsView.vue';
import TrustSliderPopover from '@/components/cards/TrustSliderPopover.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useTrustSetting } from '@/composables/useTrustSetting';
import DeckIndexLayout from '@/pages/decks/partials/DeckIndexLayout.vue';
import type { CardStatsPayload, DeckIndexSharedProps } from '@/types/decks';
import { Deferred, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<
    DeckIndexSharedProps & {
        archetype: number;
        timeframe: string;
        cardStats?: CardStatsPayload;
    }
>();

const deckWinrate = computed(() => props.cardStats?.deckWinrate ?? { wins: 0, games: 0, rate: 0.5 });
const trust = useTrustSetting(props.cardStats?.trust ?? 50);

const trustMax = computed<number>(() => {
    const games = deckWinrate.value.games;
    if (!Number.isFinite(games) || games <= 0) return 200;
    return Math.max(200, Math.ceil((games * 2) / 50) * 50);
});

function setTimeframe(value: string) {
    router.get(
        ArchetypeCardStatsController.url({ archetype: props.archetype }),
        { format: props.filters.format || undefined, timeframe: value !== 'alltime' ? value : undefined },
        { preserveScroll: true },
    );
}

function handleFilterChange(params: { archetype?: string; playDraw?: string; board?: string; perspective?: string }) {
    router.reload({
        only: ['cardStats'],
        data: {
            card_stats_archetype: params.archetype ?? '',
            card_stats_play_draw: params.playDraw ?? '',
            card_stats_board: params.board ?? '',
            card_stats_perspective: params.perspective ?? '',
        },
    });
}
</script>

<template>
    <DeckIndexLayout
        tab="card-stats"
        :timeframe="timeframe"
        :format-options="formatOptions"
        :archetype-options="archetypeOptions"
        :unclassified-count="unclassifiedCount"
        :archetype-header="archetypeHeader"
        :archetypes="archetypes"
        :filters="filters"
    >
        <template #toolbar>
            <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />
            <div class="ml-auto">
                <TrustSliderPopover :model-value="trust.value.value" :max="trustMax" @update:model-value="trust.setValue" @reset="trust.reset" />
            </div>
        </template>

        <Deferred data="cardStats">
            <template #fallback>
                <Card class="gap-0 overflow-hidden p-0">
                    <CardContent class="flex flex-col gap-2 px-4 py-4">
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-3/4" />
                    </CardContent>
                </Card>
            </template>

            <CardStatsView
                v-if="cardStats"
                :stats="cardStats.stats"
                :archetypes="cardStats.archetypes"
                :perspective="cardStats.perspective ?? 'mine'"
                :deck-winrate-rate="deckWinrate.rate"
                :trust-value="trust.value.value"
                @filter-change="handleFilterChange"
            />
        </Deferred>
    </DeckIndexLayout>
</template>
