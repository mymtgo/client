<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import CurveAndColours from '@/components/decks/CurveAndColours.vue';
import { Skeleton } from '@/components/ui/skeleton';
import LimitedEventLayout from '@/layouts/LimitedEventLayout.vue';
import { normalizeType, TYPE_ORDER } from '@/lib/cardTypes';
import CardImageGroups, { type CardImageGroup } from '@/pages/limited/partials/CardImageGroups.vue';
import DeckChanges from '@/pages/limited/partials/DeckChanges.vue';
import VersionStrip from '@/pages/limited/partials/VersionStrip.vue';
import { cardFor, timeLabel, type DeckEvolution } from '@/types/limited';
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: [AppLayout, LimitedEventLayout] });

const props = defineProps<{
    event: App.Data.Front.LimitedEventData;
    currentPage: string;
    evolution?: DeckEvolution;
}>();

const current = computed(() => props.evolution?.versions.find((version) => version.isCurrent) ?? null);

/** Version whose pool placement is shown; defaults to the current build once the deferred prop lands. */
const selectedIndex = ref<number | null>(null);
watch(current, (version) => (selectedIndex.value = version?.index ?? null), { immediate: true });

const selected = computed(() => props.evolution?.versions.find((version) => version.index === selectedIndex.value) ?? current.value);

/** Sealed has no draft: the pool is the boosters opened, plus the one added mid-run. */
const isSealed = computed(() => props.event.kind === 'sealed');

type Tab = 'main' | 'pool';
const tab = ref<Tab>('main');
const tabs = [
    { value: 'main', label: 'Maindeck' },
    { value: 'pool', label: 'Card pool' },
];

/** One card at a quantity, in the shape the deck page's card images take. */
function imageCard(catalogId: number, quantity: number): App.Data.Front.CardData {
    const card = cardFor(props.evolution?.cards ?? {}, catalogId);

    return {
        mtgoId: catalogId,
        name: card.name,
        type: card.type,
        identity: card.colors.split('').join(','),
        image: card.image,
        artCrop: card.artCrop,
        cmc: card.cmc,
        quantity,
        sideboard: false,
    };
}

const byCurve = (a: App.Data.Front.CardData, b: App.Data.Front.CardData): number =>
    (a.cmc ?? 0) - (b.cmc ?? 0) || (a.name ?? '').localeCompare(b.name ?? '');

/** The selected build's main deck, grouped by card type like a constructed decklist. */
const mainGroups = computed<CardImageGroup[]>(() => {
    const groups = new Map<string, App.Data.Front.CardData[]>();
    for (const row of selected.value?.mainCards ?? []) {
        const card = imageCard(row.catalogId, row.quantity);
        const type = normalizeType(card.type ?? 'Other');
        groups.set(type, [...(groups.get(type) ?? []), card]);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99))
        .map(([type, cards]) => ({ key: type, label: type, cards: cards.sort(byCurve) }));
});

/** Every copy in the pool, main deck included: what the boosters (or picks) gave you to build with. */
const wholePool = computed<App.Data.Front.CardData[]>(() =>
    (props.evolution?.pool.groups ?? []).flatMap((group) => group.cards.map((card) => imageCard(card.catalogId, card.quantity))),
);

const mainCardList = computed(() => mainGroups.value.flatMap((group) => group.cards));

/**
 * Every pool copy the selected build left out of the main deck, grouped by
 * colour. Copies from a sealed run's added booster get their own group first.
 */
const poolGroups = computed<CardImageGroup[]>(() => {
    const added: App.Data.Front.CardData[] = [];
    const groups = (selected.value?.pool.groups ?? props.evolution?.pool.groups ?? []).map((group) => {
        const cards: App.Data.Front.CardData[] = [];
        for (const card of group.cards) {
            const left = card.quantity - card.mainQty;
            const fromBooster = Math.min(card.added, Math.max(left, 0));
            if (fromBooster > 0) added.push(imageCard(card.catalogId, fromBooster));
            if (left - fromBooster > 0) cards.push(imageCard(card.catalogId, left - fromBooster));
        }

        return { key: group.key, label: group.label, cards: cards.sort(byCurve) };
    });

    return [...(added.length > 0 ? [{ key: 'added', label: 'Added booster', cards: added.sort(byCurve) }] : []), ...groups].filter(
        (group) => group.cards.length > 0,
    );
});
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <Head :title="`${event.title} · Deck`" />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-base font-semibold tracking-tight">Deck</h1>
                <p v-if="evolution" class="text-xs text-muted-foreground">
                    {{ evolution.summary.drafted }} {{ isSealed ? 'in pool' : 'drafted' }}
                    <template v-if="evolution.summary.added > 0">({{ evolution.summary.added }} from the added booster)</template>
                    · {{ evolution.summary.mainSpells }} spells registered + {{ evolution.summary.basics }} basics ·
                    {{ evolution.summary.versionCount }} registered version{{ evolution.summary.versionCount === 1 ? '' : 's' }}
                    <template v-if="evolution.summary.firstRegisteredAt">
                        · deck built {{ timeLabel(evolution.summary.firstRegisteredAt) }} → {{ timeLabel(evolution.summary.lastRegisteredAt) }}
                    </template>
                </p>
                <Skeleton v-else class="h-4 w-96" />
            </div>
        </div>

        <template v-if="!evolution">
            <Skeleton class="h-10 w-full" />
            <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <Skeleton class="h-96 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>
        </template>
        <template v-else>
            <VersionStrip :versions="evolution.versions" :selected="selected?.index ?? null" @select="selectedIndex = $event" />
            <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <div class="flex min-w-0 flex-col gap-4">
                    <SegmentedControl :model-value="tab" :options="tabs" class="self-start" @update:model-value="(value) => (tab = value as Tab)" />
                    <CardImageGroups v-if="tab === 'main'" :groups="mainGroups" empty-text="No registered deck yet." />
                    <CardImageGroups v-else :groups="poolGroups" empty-text="Every card in the pool is in the main deck." />
                </div>
                <div class="flex flex-col gap-6 self-start">
                    <CurveAndColours :cards="mainCardList" scope="maindeck, nonland" />
                    <CurveAndColours :cards="wholePool" scope="card pool, nonland" />
                    <DeckChanges :versions="evolution.versions" :cards="evolution.cards" :selected="selected?.index ?? null" />
                </div>
            </div>
        </template>
    </div>
</template>
