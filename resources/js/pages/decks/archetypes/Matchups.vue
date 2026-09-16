<script setup lang="ts">
import ArchetypeMatchupsController from '@/actions/App/Http/Controllers/Decks/Archetypes/MatchupsController';
import MatchupSpreadTable from '@/components/matchups/MatchupSpreadTable.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import DeckIndexLayout from '@/pages/decks/partials/DeckIndexLayout.vue';
import type { DeckIndexSharedProps, MatchupSpread } from '@/types/decks';
import { Deferred, router } from '@inertiajs/vue3';

const props = defineProps<
    DeckIndexSharedProps & {
        archetype: number;
        timeframe: string;
        matchupSpread?: MatchupSpread[];
    }
>();

function setTimeframe(value: string) {
    router.get(
        ArchetypeMatchupsController.url({ archetype: props.archetype }),
        { format: props.filters.format || undefined, timeframe: value !== 'alltime' ? value : undefined },
        { preserveScroll: true },
    );
}
</script>

<template>
    <DeckIndexLayout
        tab="matchups"
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
        </template>

        <Deferred data="matchupSpread">
            <template #fallback>
                <Card class="gap-0 overflow-hidden p-0">
                    <CardContent class="flex flex-col gap-2 px-4 py-4">
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-3/4" />
                    </CardContent>
                </Card>
            </template>

            <MatchupSpreadTable v-if="matchupSpread" :matchup-spread="matchupSpread" :timeframe="timeframe" />
        </Deferred>
    </DeckIndexLayout>
</template>
