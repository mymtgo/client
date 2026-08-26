<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { Skeleton } from '@/components/ui/skeleton';
import LimitedEventLayout from '@/Layouts/LimitedEventLayout.vue';
import DeckChanges from '@/pages/limited/partials/DeckChanges.vue';
import LimitedDecklist from '@/pages/limited/partials/LimitedDecklist.vue';
import VersionStrip from '@/pages/limited/partials/VersionStrip.vue';
import { timeLabel, type DeckEvolution } from '@/types/limited';
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

/** Drafted cards that never made a registered list for the selected build. */
const cutRows = computed(() =>
    (selected.value?.pool.groups ?? props.evolution?.pool.groups ?? [])
        .flatMap((group) => group.cards)
        .filter((card) => card.status === 'cut' || card.status === 'pool')
        .map((card) => ({ catalogId: card.catalogId, quantity: card.quantity })),
);
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <Head :title="`${event.title} · Deck`" />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-base font-semibold tracking-tight">Deck</h1>
                <p v-if="evolution" class="text-xs text-muted-foreground">
                    {{ evolution.summary.drafted }} drafted · {{ evolution.summary.mainSpells }} spells registered +
                    {{ evolution.summary.basics }} basics · {{ evolution.summary.sideboard }} sideboard ·
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
            <div class="grid gap-4 lg:grid-cols-[2fr_1fr_1fr]">
                <Skeleton class="h-96 w-full" />
                <Skeleton class="h-96 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>
        </template>
        <template v-else>
            <VersionStrip :versions="evolution.versions" :selected="selected?.index ?? null" @select="selectedIndex = $event" />
            <div class="grid gap-4 lg:grid-cols-[2fr_1fr_1fr]">
                <LimitedDecklist
                    title="Main deck"
                    :rows="selected?.mainCards ?? []"
                    :cards="evolution.cards"
                    grouped
                    :extra="{ label: 'Drafted, not registered', rows: cutRows }"
                    empty-text="No registered deck yet."
                />
                <LimitedDecklist
                    title="Sideboard"
                    :rows="selected?.sideCards ?? []"
                    :cards="evolution.cards"
                    empty-text="Empty."
                    class="self-start"
                />
                <DeckChanges :versions="evolution.versions" :cards="evolution.cards" :selected="selected?.index ?? null" class="self-start" />
            </div>
        </template>
    </div>
</template>
