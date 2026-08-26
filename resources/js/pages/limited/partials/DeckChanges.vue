<script setup lang="ts">
import CardHoverPreview from '@/components/cards/CardHoverPreview.vue';
import ManaPips from '@/components/limited/ManaPips.vue';
import { cardFor, type DeckDiffEntry, type DeckVersionRow, type LimitedCards } from '@/types/limited';
import { computed } from 'vue';

/**
 * What changed between the selected build and the one before it, in the
 * zone list style: signed count cell, art crop, name, colour pips.
 */
const props = defineProps<{ versions: DeckVersionRow[]; cards: LimitedCards; selected: number | null }>();

type ChangeRow = { key: string; sign: '+' | '−'; entry: DeckDiffEntry; name: string; image: string | null; artCrop: string | null; colors: string };

const active = computed(() => props.versions.find((version) => version.index === props.selected) ?? null);
const previous = computed(() => (active.value ? (props.versions.find((version) => version.index === active.value!.index - 1) ?? null) : null));

function rows(entries: DeckDiffEntry[], sign: '+' | '−', prefix: string): ChangeRow[] {
    return entries.map((entry) => {
        const card = cardFor(props.cards, entry.catalogId);
        return {
            key: `${prefix}${sign}${entry.catalogId}`,
            sign,
            entry,
            name: card.name,
            image: card.image,
            artCrop: card.artCrop,
            colors: card.colors,
        };
    });
}

const sections = computed(() => {
    if (!active.value || !previous.value) return [];
    const main = [...rows(active.value.diffMain.added, '+', 'm'), ...rows(active.value.diffMain.removed, '−', 'm')];
    const side = [...rows(active.value.diffSide.added, '+', 's'), ...rows(active.value.diffSide.removed, '−', 's')];
    return [
        { key: 'main', label: 'Main deck', rows: main },
        { key: 'side', label: 'Sideboard', rows: side },
    ].filter((section) => section.rows.length);
});
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-lg border border-black/60 bg-card">
        <div class="flex items-baseline justify-between gap-2 px-4 py-3">
            <h2 class="text-sm font-semibold">Changes</h2>
            <span v-if="active && previous" class="text-xs text-muted-foreground">v{{ active.index }} vs v{{ previous.index }}</span>
        </div>

        <div v-if="!active" class="border-t px-4 py-3 text-xs text-muted-foreground">No registered deck yet.</div>
        <div v-else-if="!previous" class="border-t px-4 py-3 text-xs text-muted-foreground">First build after deck construction.</div>
        <div v-else-if="!sections.length" class="border-t px-4 py-3 text-xs text-muted-foreground">Identical to v{{ previous.index }}.</div>

        <div v-for="section in sections" :key="section.key">
            <h3
                class="flex items-baseline justify-between gap-2 border-y py-2 pr-3 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
            >
                <span>{{ section.label }}</span>
                <span class="tabular-nums">{{ section.rows.length }}</span>
            </h3>
            <div class="divide-y text-xs">
                <CardHoverPreview v-for="row in section.rows" :key="row.key" :image="row.image" :name="row.name">
                    <div class="flex items-center text-sm hover:bg-muted/40">
                        <span
                            class="w-8 shrink-0 border-r bg-black/20 px-2 py-1 text-center font-semibold tabular-nums"
                            :class="row.sign === '+' ? 'text-emerald-400' : 'text-rose-400'"
                        >
                            {{ row.sign }}{{ row.entry.quantity }}
                        </span>
                        <span class="h-7 w-7 shrink-0 overflow-hidden bg-black/20">
                            <img v-if="row.artCrop" :src="row.artCrop" :alt="row.name" class="h-full w-full object-cover" />
                        </span>
                        <span class="min-w-0 grow truncate px-2">{{ row.name }}</span>
                        <span class="flex shrink-0 items-center px-3"><ManaPips v-if="row.colors" :colors="row.colors" /></span>
                    </div>
                </CardHoverPreview>
            </div>
        </div>
    </div>
</template>
