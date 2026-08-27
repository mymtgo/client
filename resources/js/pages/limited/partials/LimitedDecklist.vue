<script setup lang="ts">
import CardHoverPreview from '@/components/cards/CardHoverPreview.vue';
import ManaPips from '@/components/limited/ManaPips.vue';
import OverlayCardRow from '@/components/overlay/OverlayCardRow.vue';
import { cardFor, type DeckCardQty, type LimitedCards } from '@/types/limited';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * One zone of a registered limited deck in the overlay's list style: count
 * cell, art crop, name, colour pips. `grouped` splits the list by card type
 * (main deck); ungrouped is a flat list (sideboard). `extra` is an optional
 * collapsed tail for cards that were drafted but never registered.
 */
const props = defineProps<{
    title: string;
    rows: DeckCardQty[];
    cards: LimitedCards;
    grouped?: boolean;
    extra?: { label: string; rows: DeckCardQty[] };
    emptyText?: string;
}>();

type Row = DeckCardQty & { name: string; colors: string; image: string | null; artCrop: string | null; cmc: number };

const TYPE_ORDER = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'] as const;
const TYPE_LABELS: Record<string, string> = {
    Creature: 'Creatures',
    Planeswalker: 'Planeswalkers',
    Battle: 'Battles',
    Instant: 'Instants',
    Sorcery: 'Sorceries',
    Enchantment: 'Enchantments',
    Artifact: 'Artifacts',
    Land: 'Lands',
    Other: 'Other',
};

function typeKey(type: string | null): string {
    for (const canonical of TYPE_ORDER) {
        if (type?.includes(canonical)) return canonical;
    }
    return 'Other';
}

function toRow(entry: DeckCardQty): Row {
    const card = cardFor(props.cards, entry.catalogId);
    return { ...entry, name: card.name, colors: card.colors, image: card.image, artCrop: card.artCrop, cmc: card.cmc ?? 0 };
}

const sortRows = (rows: Row[]): Row[] => rows.sort((a, b) => a.cmc - b.cmc || a.name.localeCompare(b.name));
const count = (rows: DeckCardQty[]): number => rows.reduce((sum, entry) => sum + entry.quantity, 0);

const groups = computed(() => {
    if (!props.grouped) {
        return [{ key: 'all', label: null, rows: sortRows(props.rows.map(toRow)), count: count(props.rows) }];
    }
    const byType = new Map<string, Row[]>();
    for (const entry of props.rows) {
        const key = typeKey(cardFor(props.cards, entry.catalogId).type);
        const list = byType.get(key) ?? [];
        list.push(toRow(entry));
        byType.set(key, list);
    }
    return [...TYPE_ORDER, 'Other']
        .filter((key) => byType.has(key))
        .map((key) => ({ key, label: TYPE_LABELS[key], rows: sortRows(byType.get(key)!), count: count(byType.get(key)!) }));
});

/**
 * Grouped lists print in two columns like a paper decklist: creature-ish
 * groups on the left, spells and lands on the right, so 40 cards read at
 * about half the height of one long column.
 */
const CREATURE_KEYS = new Set(['Creature', 'Planeswalker', 'Battle']);
const columns = computed(() => {
    if (!props.grouped) return [groups.value];
    const left = groups.value.filter((group) => CREATURE_KEYS.has(group.key));
    const right = groups.value.filter((group) => !CREATURE_KEYS.has(group.key));
    return left.length && right.length ? [left, right] : [groups.value];
});

const extraRows = computed(() => (props.extra ? sortRows(props.extra.rows.map(toRow)) : []));
const showExtra = ref(false);
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-lg border border-black/60 bg-card">
        <div class="flex items-baseline justify-between gap-2 px-4 py-3">
            <h2 class="text-sm font-semibold">{{ title }}</h2>
            <span class="text-xs text-muted-foreground tabular-nums">{{ count(rows) }}</span>
        </div>

        <div v-if="!rows.length" class="px-4 pb-4 text-xs text-muted-foreground">{{ emptyText ?? 'Nothing registered.' }}</div>

        <div class="grid border-t" :class="columns.length > 1 ? 'md:grid-cols-2 md:divide-x' : ''">
            <div v-for="(column, c) in columns" :key="c" class="min-w-0">
                <div v-for="group in column" :key="group.key">
                    <h3
                        v-if="group.label"
                        class="flex items-baseline justify-between gap-2 border-b py-2 pr-3 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
                    >
                        <span>{{ group.label }}</span>
                        <span class="tabular-nums">{{ group.count }}</span>
                    </h3>
                    <div class="divide-y border-b text-xs">
                        <CardHoverPreview v-for="card in group.rows" :key="card.catalogId" :image="card.image" :name="card.name">
                            <OverlayCardRow :name="card.name" :count="card.quantity" :art-crop="card.artCrop" class="hover:bg-muted/40">
                                <span class="flex shrink-0 items-center px-3">
                                    <ManaPips v-if="card.colors" :colors="card.colors" />
                                </span>
                            </OverlayCardRow>
                        </CardHoverPreview>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="extra && extraRows.length">
            <button
                type="button"
                class="flex w-full items-center justify-between gap-2 py-2 pr-3 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase hover:text-foreground"
                :aria-expanded="showExtra"
                @click="showExtra = !showExtra"
            >
                <span class="inline-flex items-center gap-1.5">
                    <ChevronDown class="size-3.5 transition-transform" :class="showExtra ? 'rotate-180' : ''" />
                    {{ extra.label }}
                </span>
                <span class="tabular-nums">{{ count(extra.rows) }}</span>
            </button>
            <div v-if="showExtra" class="divide-y border-t text-xs opacity-70">
                <CardHoverPreview v-for="card in extraRows" :key="card.catalogId" :image="card.image" :name="card.name">
                    <OverlayCardRow :name="card.name" :count="card.quantity" :art-crop="card.artCrop" class="hover:bg-muted/40">
                        <span class="flex shrink-0 items-center px-3">
                            <ManaPips v-if="card.colors" :colors="card.colors" />
                        </span>
                    </OverlayCardRow>
                </CardHoverPreview>
            </div>
        </div>
    </div>
</template>
