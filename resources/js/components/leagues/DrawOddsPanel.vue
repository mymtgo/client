<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { computed, ref } from 'vue';

/**
 * Presentational draw-odds overlay. Mirrors the visual language of the deck
 * popout (resources/js/pages/decks/Popout.vue) — cards grouped by type with a
 * color left-border, a leading bold count, the card name, and mana symbols on
 * the right, plus a hover card-image preview anchored top-right inside the
 * window. The extras over the popout are a per-card remaining/total count, a
 * next-draw percentage per card, and a top-5 type-probability footer.
 *
 * The backend serializes `cards` and `topFive` as plain arrays at runtime, but
 * the generated types describe them as keyed records (a Spatie DataCollection
 * artifact). Override those two members to arrays so we get array semantics.
 */
type DrawOddsCard = App.Data.Front.DrawOddsCardData;
type DrawOddsType = App.Data.Front.DrawOddsTypeData;
type DrawOdds = Omit<App.Data.Front.DrawOddsData, 'cards' | 'topFive'> & {
    cards: DrawOddsCard[];
    topFive: DrawOddsType[];
};

const props = defineProps<{ drawOdds: DrawOdds | null }>();

const hoveredCard = ref<DrawOddsCard | null>(null);
const previewTop = ref(0);

function onCardEnter(card: DrawOddsCard, event: MouseEvent): void {
    if (!card.image) {
        return;
    }
    hoveredCard.value = card;
    const rowTop = (event.currentTarget as HTMLElement).getBoundingClientRect().top;
    // Card image is ~280px tall at 200px wide (MTG ratio). Clamp so it stays in viewport.
    const maxTop = window.innerHeight - 280;
    previewTop.value = Math.max(8, Math.min(rowTop, maxTop));
}

function onCardLeave(): void {
    hoveredCard.value = null;
}

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
    const colors = identity.split(',').map((c) => COLOR_MAP[c.trim()]).filter(Boolean);
    if (colors.length === 0) return FALLBACK_COLOR;
    if (colors.length === 1) return colors[0];
    const pct = 100 / colors.length;
    const stops = colors.map((c, i) => `${c} ${i * pct}% ${(i + 1) * pct}%`).join(', ');
    return `linear-gradient(to bottom, ${stops})`;
}

function borderStyle(identity: string | null): Record<string, string> {
    const val = colorBorder(identity);
    if (val.startsWith('linear-gradient')) {
        return { borderImage: `${val} 1`, borderLeftWidth: '4px', borderLeftStyle: 'solid' };
    }
    return { borderLeftColor: val, borderLeftWidth: '4px', borderLeftStyle: 'solid' };
}

const CANONICAL_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'] as const;
const TYPE_ORDER: Record<string, number> = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

function normalizeType(raw: string): string {
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}

const groupedCards = computed<Record<string, DrawOddsCard[]>>(() => {
    const merged: Record<string, DrawOddsCard[]> = {};
    for (const card of props.drawOdds?.cards ?? []) {
        const key = normalizeType(card.type);
        (merged[key] ??= []).push(card);
    }
    return Object.fromEntries(
        Object.entries(merged).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)),
    );
});

// No active match at all — nothing to show yet.
const isWaiting = computed(() => !props.drawOdds);
// Active match, but the deck couldn't be resolved into usable card data
// (e.g. a legacy deck format with no card list).
const hasNoDeckData = computed(() => !!props.drawOdds && props.drawOdds.cards.length === 0);
const isEmpty = computed(() => isWaiting.value || hasNoDeckData.value);

const getRemaining = (cards: DrawOddsCard[]): number => cards.reduce((sum, c) => sum + c.remaining, 0);

const pct = (value: number, digits = 1): string => `${(value * 100).toFixed(digits)}%`;
</script>

<template>
    <div class="relative flex h-full flex-col bg-background text-foreground">
        <!-- Empty / waiting state -->
        <div v-if="isEmpty" class="flex h-full items-center justify-center p-6" style="-webkit-app-region: drag">
            <p class="text-sm text-muted-foreground">
                {{ isWaiting ? 'Waiting for match…' : 'No deck data for this match' }}
            </p>
        </div>

        <template v-else-if="drawOdds">
            <!-- Header -->
            <div class="shrink-0 p-4" style="-webkit-app-region: drag">
                <h1 class="text-xl font-bold leading-tight">Draw Odds</h1>
                <p class="text-sm text-muted-foreground">
                    Library &middot;
                    <span class="tabular-nums">{{ drawOdds.librarySize }}</span> cards
                </p>
            </div>

            <!-- Decklist -->
            <div class="flex-1 space-y-4 overflow-y-auto px-4 pb-4">
                <div v-for="(cards, type) in groupedCards" :key="type" class="mb-3">
                    <h3 class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground/60">
                        {{ type }} ({{ getRemaining(cards) }})
                    </h3>
                    <div
                        v-for="card in cards"
                        :key="card.mtgoId ?? card.name"
                        :style="borderStyle(card.identity)"
                        class="flex items-center justify-between gap-2 py-1 pl-2.5 pr-1.5 text-sm"
                        :class="{ 'opacity-40': card.remaining === 0 }"
                        @mouseenter="onCardEnter(card, $event)"
                        @mouseleave="onCardLeave"
                    >
                        <span class="min-w-0 truncate" :class="{ 'line-through': card.remaining === 0 }">
                            <span class="font-semibold tabular-nums">{{ card.remaining }}/{{ card.total }}</span>
                            {{ card.name }}
                        </span>
                        <div class="flex shrink-0 items-center gap-2">
                            <ManaSymbols :symbols="card.identity" class="shrink-0" />
                            <span class="w-12 text-right font-medium tabular-nums text-muted-foreground">
                                {{ pct(card.drawChance) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top-5 footer: P(draw >=1 of type in next 5) -->
            <div
                v-if="drawOdds.topFive.length"
                class="shrink-0 border-t border-border px-4 py-2.5"
            >
                <h2 class="mb-1 text-[0.625rem] font-semibold uppercase tracking-wider text-muted-foreground/60">
                    In next 5 draws
                </h2>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span
                        v-for="bucket in drawOdds.topFive"
                        :key="bucket.type"
                        class="inline-flex items-baseline gap-1"
                    >
                        <span class="text-muted-foreground">{{ bucket.type }}</span>
                        <span class="font-semibold tabular-nums">{{ pct(bucket.probability, 0) }}</span>
                    </span>
                </div>
            </div>

            <!-- Card image preview (inside window, anchored top-right) -->
            <Transition name="fade">
                <div
                    v-if="hoveredCard?.image"
                    class="pointer-events-none fixed right-2 z-50"
                    :style="{ top: `${previewTop}px` }"
                >
                    <img
                        :src="hoveredCard.image"
                        :alt="hoveredCard.name"
                        class="w-[200px] rounded-lg shadow-xl ring-1 ring-border"
                    />
                </div>
            </Transition>
        </template>
    </div>
</template>

<style scoped>
.fade-enter-active { transition: opacity 0.1s ease; }
.fade-leave-active { transition: opacity 0.05s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
