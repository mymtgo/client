<script setup lang="ts">
import ArchetypeShowController from '@/actions/App/Http/Controllers/Archetypes/ShowController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchRecord from '@/components/MatchRecord.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { DECK_INDEX_TABS, deckIndexTabUrl } from '@/pages/decks/partials/deckIndexTabs';
import type { DeckIndexTab } from '@/types/decks';
import { Link, router } from '@inertiajs/vue3';
import { ArrowUpRight, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        header: App.Data.Front.DeckArchetypeHeaderData;
        tab?: DeckIndexTab;
        format?: string;
        timeframe?: string;
    }>(),
    { tab: 'decks', format: '', timeframe: 'alltime' },
);

// Fallbacks ("Other") span formats and have no matchup or card story to tell,
// so they only ever get the deck grid.
const showTabs = computed(() => props.header.archetype !== null && !props.header.archetype.isFallback);

const tabOptions = DECK_INDEX_TABS.map((item) => ({ value: item.key, label: item.label }));

function openTab(value: string) {
    if (props.header.archetype === null || value === props.tab) return;
    router.visit(deckIndexTabUrl(value as DeckIndexTab, props.header.archetype.id, props.format, props.timeframe));
}

const winrateClass = computed(() => {
    if (props.header.record.total === 0) return 'text-muted-foreground';
    return props.header.record.winrate >= 50 ? 'text-success' : 'text-destructive';
});

function matchupClass(winrate: number): string {
    return winrate >= 50 ? 'text-success' : 'text-destructive';
}
</script>

<!--
    Sits between the top bar and the grid whenever one archetype (or
    Unclassified) is selected. Record and deck count come from the same
    aggregate as the sidebar row, so the two never disagree.
-->
<template>
    <section class="flex shrink-0 flex-col gap-2 border-t border-b border-white/5 border-b-black/60 bg-background/40 px-4 py-3">
        <div class="flex items-center gap-3">
            <template v-if="header.archetype">
                <ManaSymbols v-if="header.archetype.colorIdentity" :symbols="header.archetype.colorIdentity" class="shrink-0" />
                <h2 class="truncate text-lg font-semibold">{{ header.archetype.name }}</h2>
                <Link
                    :href="ArchetypeShowController({ archetype: header.archetype.id }).url"
                    class="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    Archetype page
                    <ArrowUpRight class="size-3.5" />
                </Link>
            </template>
            <template v-else>
                <TriangleAlert class="size-4 shrink-0 text-warning" />
                <h2 class="text-lg font-semibold">Unclassified</h2>
                <span class="ml-auto text-xs text-muted-foreground">Drag decks onto an archetype to classify them</span>
            </template>
        </div>

        <div class="flex flex-wrap items-baseline gap-x-2 text-sm tabular-nums">
            <span :class="['text-2xl leading-none font-bold', winrateClass]">
                {{ header.record.total === 0 ? '—' : `${header.record.winrate}%` }}
            </span>
            <span class="text-muted-foreground">win rate</span>
            <span class="text-muted-foreground">·</span>
            <MatchRecord :record="header.record" />
            <span class="text-muted-foreground">·</span>
            <span class="text-muted-foreground">{{ header.record.total }} matches</span>
            <span class="text-muted-foreground">·</span>
            <span class="text-muted-foreground">{{ header.deckCount }} {{ header.deckCount === 1 ? 'deck' : 'decks' }}</span>
        </div>

        <div v-if="header.archetype" class="flex flex-wrap gap-x-6 gap-y-1 text-xs">
            <template v-if="header.bestMatchup && header.worstMatchup">
                <span class="flex items-center gap-1.5">
                    <span class="text-muted-foreground">Best matchup</span>
                    <ManaSymbols v-if="header.bestMatchup.colorIdentity" :symbols="header.bestMatchup.colorIdentity" />
                    <span class="font-medium">{{ header.bestMatchup.name }}</span>
                    <span :class="['tabular-nums', matchupClass(header.bestMatchup.winrate)]">{{ header.bestMatchup.winrate }}%</span>
                    <span class="text-muted-foreground tabular-nums">({{ header.bestMatchup.matches }} matches)</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="text-muted-foreground">Worst matchup</span>
                    <ManaSymbols v-if="header.worstMatchup.colorIdentity" :symbols="header.worstMatchup.colorIdentity" />
                    <span class="font-medium">{{ header.worstMatchup.name }}</span>
                    <span :class="['tabular-nums', matchupClass(header.worstMatchup.winrate)]">{{ header.worstMatchup.winrate }}%</span>
                    <span class="text-muted-foreground tabular-nums">({{ header.worstMatchup.matches }} matches)</span>
                </span>
            </template>
            <span v-else class="text-muted-foreground">Not enough matchup data</span>
        </div>

        <SegmentedControl v-if="showTabs" class="mt-1" :model-value="tab" :options="tabOptions" @update:model-value="openTab" />
    </section>
</template>
