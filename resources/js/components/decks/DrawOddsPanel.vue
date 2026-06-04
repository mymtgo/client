<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { hypergeometric } from '@/composables/useHypergeometric';
import { computed, ref, watch } from 'vue';

/**
 * Presentational draw-odds overlay. Mirrors the visual language of the deck
 * popout (resources/js/pages/decks/Popout.vue) — cards grouped by type with a
 * color left-border, a leading bold count, the card name, and mana symbols on
 * the right, plus a hover card-image preview anchored top-right inside the
 * window. The extras over the popout are a per-card remaining/total count and
 * a player-controlled sample-size stepper showing the per-card chance to draw
 * at least one copy in the next N draws.
 *
 * The backend serializes `cards` as plain arrays at runtime, but the generated
 * types describe them as keyed records (a Spatie DataCollection artifact).
 * Override that member to an array so we get array semantics.
 */
type DrawOddsCard = App.Data.Front.DrawOddsCardData;
type DrawOdds = Omit<App.Data.Front.DrawOddsData, 'cards'> & {
    cards: DrawOddsCard[];
};

const props = defineProps<{ drawOdds: DrawOdds | null }>();

const sampleSize = ref(1);

// Library only ever shrinks during a game; cap the sample at what's left.
const maxSample = computed(() => Math.max(1, props.drawOdds?.librarySize ?? 1));

const canDecrement = computed(() => sampleSize.value > 1);
const canIncrement = computed(() => sampleSize.value < maxSample.value);

function decrement(): void {
    if (canDecrement.value) sampleSize.value--;
}

function increment(): void {
    if (canIncrement.value) sampleSize.value++;
}

// When the library shrinks below the chosen sample, pull the stepper back in
// so its value never exceeds its own max.
watch(maxSample, (max) => {
    if (sampleSize.value > max) sampleSize.value = max;
});

// P(at least one copy of this card in the next `sampleSize` draws). A card with
// no copies left can never be drawn — short-circuit to 0 (the shared composable
// clamps the wanted count down to 0 when there are 0 successes, which would
// otherwise report P(X>=0) = 100%).
function drawChance(card: DrawOddsCard): number {
    if (card.remaining <= 0) {
        return 0;
    }
    const library = props.drawOdds?.librarySize ?? 0;
    return hypergeometric(library, card.remaining, sampleSize.value, 1).atLeast;
}

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
    return Object.fromEntries(Object.entries(merged).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)));
});

// No active match at all — nothing to show yet.
const isWaiting = computed(() => !props.drawOdds);
// Active match, but the deck couldn't be resolved into usable card data
// (e.g. a legacy deck format with no card list).
const hasNoDeckData = computed(() => !!props.drawOdds && props.drawOdds.cards.length === 0);
const isEmpty = computed(() => isWaiting.value || hasNoDeckData.value);

const getRemaining = (cards: DrawOddsCard[]): number => cards.reduce((sum, c) => sum + c.remaining, 0);

// P(at least one card of this type in the next `sampleSize` draws), summing the
// type's remaining copies as the success pool. Zero remaining → 0 (see drawChance).
function typeChance(cards: DrawOddsCard[]): number {
    const remaining = getRemaining(cards);
    if (remaining <= 0) {
        return 0;
    }
    const library = props.drawOdds?.librarySize ?? 0;
    return hypergeometric(library, remaining, sampleSize.value, 1).atLeast;
}

// Format a probability as a percentage. Crucially, an uncertain outcome
// (0 < value < 1) must never *round* to 0% or 100% — that would claim
// impossibility/certainty the math doesn't support (e.g. 99.82% at 0 digits).
// Clamp the displayed number just inside the bounds unless the true value is
// exactly 0 or 1.
const pct = (value: number, digits = 1): string => {
    let display = value * 100;
    if (value > 0 && value < 1) {
        const step = 10 ** -digits;
        display = Math.min(Math.max(display, step), 100 - step);
    }
    return `${display.toFixed(digits)}%`;
};

// Track per-card remaining so we can flash a row briefly when its count
// changes between snapshots — both decreases (drawn / discarded / milled)
// and increases (Lembas-style shuffle-back). Keyed by mtgoId or, as a
// fallback, the card name. Highlight clears after 1.2s per card.
const HIGHLIGHT_MS = 1200;
const cardKey = (card: DrawOddsCard): string | number => card.mtgoId ?? card.name;
const previousRemaining = new Map<string | number, number>();
const highlighted = ref<Set<string | number>>(new Set());

watch(
    () => props.drawOdds?.cards,
    (cards) => {
        if (!cards) return;

        const next = new Set(highlighted.value);

        for (const card of cards) {
            const key = cardKey(card);
            const prev = previousRemaining.get(key);

            if (prev !== undefined && prev !== card.remaining) {
                next.add(key);
                window.setTimeout(() => {
                    const after = new Set(highlighted.value);
                    after.delete(key);
                    highlighted.value = after;
                }, HIGHLIGHT_MS);
            }

            previousRemaining.set(key, card.remaining);
        }

        highlighted.value = next;
    },
);
</script>

<template>
    <div class="relative flex h-full flex-col bg-background text-foreground">
        <!-- Empty / waiting state -->
        <div v-if="isEmpty" class="flex h-full items-center justify-center p-6" style="-webkit-app-region: drag">
            <p class="text-sm text-muted-foreground">
                {{ isWaiting ? 'Waiting for match…' : 'No deck data for this match' }}
            </p>
        </div>

        <div v-else-if="drawOdds" class="flex h-full min-h-0 flex-col">
            <!-- Sample-size stepper. Acts as the drag handle for the frameless window. -->
            <div class="flex shrink-0 items-center justify-between gap-2 bg-background px-4 py-2" style="-webkit-app-region: drag">
                <span class="text-[0.625rem] font-semibold tracking-wider text-muted-foreground/60 uppercase">
                    Next {{ sampleSize === 1 ? 'draw' : 'draws' }}
                </span>
                <div class="flex items-center gap-1" style="-webkit-app-region: no-drag">
                    <button
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-30"
                        :disabled="!canDecrement"
                        aria-label="Decrease sample size"
                        @click="decrement"
                    >
                        −
                    </button>
                    <span class="w-6 text-center text-sm font-semibold tabular-nums">{{ sampleSize }}</span>
                    <button
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-30"
                        :disabled="!canIncrement"
                        aria-label="Increase sample size"
                        @click="increment"
                    >
                        +
                    </button>
                </div>
            </div>

            <!-- Decklist -->
            <div class="min-h-0 flex-1 overflow-y-auto pb-4">
                <div v-for="(cards, type) in groupedCards" :key="type">
                    <h3
                        class="flex items-baseline justify-between gap-2 border-y py-2 pr-1.5 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
                    >
                        <span>{{ type }} ({{ getRemaining(cards) }})</span>
                        <span class="w-12 shrink-0 text-right text-muted-foreground tabular-nums">{{ pct(typeChance(cards)) }}</span>
                    </h3>
                    <div class="divide-y text-xs">
                        <div
                            v-for="card in cards"
                            :key="card.mtgoId ?? card.name"
                            class="flex items-center gap-2 text-sm transition-colors duration-300"
                            :class="{
                                'opacity-20': card.remaining === 0,
                                'is-flashing': highlighted.has(cardKey(card)),
                            }"
                            @mouseenter="onCardEnter(card, $event)"
                            @mouseleave="onCardLeave"
                        >
                            <span class="min-w-0 w-8 text-center shrink-0 border-r bg-black/20 px-2 py-1">
                                <span class="font-semibold tabular-nums">{{ card.remaining }}</span>
                            </span>
                            <span class="min-w-0 grow truncate">
                                {{ card.name }}
                            </span>
                            <div class="flex shrink-0 items-center gap-2 px-2">
                                <span class="w-12 text-right font-medium text-muted-foreground tabular-nums">
                                    {{ pct(drawChance(card)) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card image preview (inside window, anchored top-right) -->
            <Transition name="fade">
                <div v-if="hoveredCard?.image" class="pointer-events-none fixed right-2 z-50" :style="{ top: `${previewTop}px` }">
                    <img :src="hoveredCard.image" :alt="hoveredCard.name" class="w-[200px] rounded-lg shadow-xl ring-1 ring-border" />
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.fade-enter-active {
    transition: opacity 0.1s ease;
}
.fade-leave-active {
    transition: opacity 0.05s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.card-row.is-flashing {
    animation: card-flash 1.2s ease-out;
}

@keyframes card-flash {
    0% {
        background-color: rgba(250, 204, 21, 0.22);
    }
    60% {
        background-color: rgba(250, 204, 21, 0.12);
    }
    100% {
        background-color: transparent;
    }
}
</style>
