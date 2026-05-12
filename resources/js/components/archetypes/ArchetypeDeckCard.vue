<script setup lang="ts">
import ExportDekController from '@/actions/App/Http/Controllers/Archetypes/ExportDekController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Download, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        archetypeId: number;
        deck: App.Data.Front.ArchetypeDeckData;
        index: number;
        showMostPlayed?: boolean;
        deletable?: boolean;
        canDelete?: boolean;
    }>(),
    { showMostPlayed: false, deletable: false, canDelete: true },
);

const exporting = ref(false);

const CANONICAL_TYPES = [
    'Creature',
    'Planeswalker',
    'Battle',
    'Instant',
    'Sorcery',
    'Enchantment',
    'Artifact',
    'Land',
] as const;
const TYPE_ORDER = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

function normalizeType(raw: string | null | undefined): string {
    if (!raw) return 'Other';
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}

const maindeck = computed(() => {
    const grouped: Record<string, App.Data.Front.CardData[]> = {};
    for (const card of props.deck.cards.filter((c) => !c.sideboard)) {
        const type = normalizeType(card.type);
        (grouped[type] ??= []).push(card);
    }
    return Object.fromEntries(
        Object.entries(grouped).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)),
    );
});

const sideboard = computed(() => props.deck.cards.filter((c) => c.sideboard));

const hasFacingRecord = computed(() => props.deck.wins + props.deck.losses > 0);

const facingDisplay = computed(() =>
    hasFacingRecord.value
        ? `${props.deck.facingWinrate}% (${props.deck.wins}-${props.deck.losses})`
        : '— (0-0)',
);

const maindeckCount = computed(() =>
    props.deck.cards.filter((c) => !c.sideboard).reduce((sum, c) => sum + c.quantity, 0),
);

const sideboardCount = computed(() =>
    sideboard.value.reduce((sum, c) => sum + c.quantity, 0),
);

const getCount = (cards: App.Data.Front.CardData[]) =>
    cards.reduce((sum, c) => sum + c.quantity, 0);

const COLOR_MAP: Record<string, string> = {
    W: '#F8F6D8',
    U: '#C1D7E9',
    B: '#BAB1AB',
    R: '#E49977',
    G: '#A3C095',
};
const FALLBACK_COLOR = '#888';

function colorBorder(identity: string | null): string {
    if (!identity) return FALLBACK_COLOR;
    const colors = identity
        .split(',')
        .map((c) => COLOR_MAP[c.trim()])
        .filter(Boolean);
    if (colors.length === 0) return FALLBACK_COLOR;
    if (colors.length === 1) return colors[0];
    const pct = 100 / colors.length;
    const stops = colors.map((c, i) => `${c} ${i * pct}% ${(i + 1) * pct}%`).join(', ');
    return `linear-gradient(to bottom, ${stops})`;
}

function borderStyle(identity: string | null): Record<string, string> {
    const val = colorBorder(identity);
    if (val.startsWith('linear-gradient')) {
        return { borderImage: `${val} 1`, borderLeftWidth: '3px', borderLeftStyle: 'solid' };
    }
    return { borderLeftColor: val, borderLeftWidth: '3px', borderLeftStyle: 'solid' };
}

const emit = defineEmits<{
    cardEnter: [card: App.Data.Front.CardData, event: MouseEvent];
    cardLeave: [];
    delete: [deckId: number];
}>();

async function exportDek() {
    exporting.value = true;
    try {
        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        await fetch(ExportDekController.url({ archetype: props.archetypeId }), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ archetype_deck_id: props.deck.id }),
        });
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <Card class="flex flex-col gap-0 overflow-hidden p-0">
        <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="text-sm font-medium text-foreground">Variant {{ index + 1 }}</span>
                <span class="text-xs text-muted-foreground">seen {{ deck.seenCount }}×</span>
                <Badge v-if="showMostPlayed" variant="secondary" class="text-[10px] uppercase tracking-wide">
                    Most played
                </Badge>
                <span
                    class="text-xs tabular-nums"
                    :class="hasFacingRecord ? 'text-orange-400' : 'text-muted-foreground'"
                    :title="hasFacingRecord ? 'Winrate against this variant' : 'No matches recorded against this variant yet'"
                >
                    {{ facingDisplay }}
                </span>
            </div>
            <div class="flex items-center gap-1">
                <Button variant="ghost" size="sm" :disabled="exporting" @click="exportDek">
                    <Download class="mr-1 size-3.5" />
                    .dek
                </Button>
                <Button
                    v-if="deletable"
                    variant="ghost"
                    size="sm"
                    :disabled="!canDelete"
                    :title="canDelete ? 'Delete variant' : 'Cannot delete the last variant'"
                    class="text-destructive hover:text-destructive"
                    @click="emit('delete', deck.id)"
                >
                    <Trash2 class="size-3.5" />
                </Button>
            </div>
        </div>

        <div class="flex-1 space-y-3 px-3 py-2">
            <div>
                <h3 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                    Main Deck ({{ maindeckCount }})
                </h3>
                <div v-for="(cards, type) in maindeck" :key="type" class="mb-2">
                    <h4 class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">
                        {{ type }} ({{ getCount(cards) }})
                    </h4>
                    <div
                        v-for="card in cards"
                        :key="card.mtgoId ?? card.name"
                        :style="borderStyle(card.identity)"
                        class="flex items-center justify-between gap-1 py-0.5 pl-2 pr-1 text-xs"
                        @mouseenter="emit('cardEnter', card, $event)"
                        @mouseleave="emit('cardLeave')"
                    >
                        <span class="truncate">
                            <span class="font-semibold tabular-nums">{{ card.quantity }}</span>
                            {{ card.name }}
                        </span>
                        <ManaSymbols :symbols="card.identity" class="shrink-0" />
                    </div>
                </div>
            </div>

            <div v-if="sideboard.length">
                <h3 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                    Sideboard ({{ sideboardCount }})
                </h3>
                <div
                    v-for="card in sideboard"
                    :key="card.mtgoId ?? card.name"
                    :style="borderStyle(card.identity)"
                    class="flex items-center justify-between gap-1 py-0.5 pl-2 pr-1 text-xs"
                    @mouseenter="emit('cardEnter', card, $event)"
                    @mouseleave="emit('cardLeave')"
                >
                    <span class="truncate">
                        <span class="font-semibold tabular-nums">{{ card.quantity }}</span>
                        {{ card.name }}
                    </span>
                    <ManaSymbols :symbols="card.identity" class="shrink-0" />
                </div>
            </div>
        </div>
    </Card>
</template>
