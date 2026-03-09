<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import { computed, ref } from 'vue';

defineOptions({ layout: OverlayLayout });

const hoveredCard = ref<App.Data.Front.CardData | null>(null);
const previewTop = ref(0);

function onCardEnter(card: App.Data.Front.CardData, event: MouseEvent) {
    hoveredCard.value = card;
    const rowTop = (event.currentTarget as HTMLElement).getBoundingClientRect().top;
    // Card image is ~280px tall at 200px wide (MTG ratio). Clamp so it stays in viewport.
    const maxTop = window.innerHeight - 280;
    previewTop.value = Math.max(8, Math.min(rowTop, maxTop));
}

function onCardLeave() {
    hoveredCard.value = null;
}

const props = defineProps<{
    deckName: string;
    format: string;
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
}>();

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
const TYPE_ORDER = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

function normalizeType(raw: string): string {
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}

const groupedMaindeck = computed(() => {
    const merged: Record<string, App.Data.Front.CardData[]> = {};
    for (const [rawType, cards] of Object.entries(props.maindeck)) {
        const key = normalizeType(rawType);
        (merged[key] ??= []).push(...cards);
    }
    return Object.fromEntries(
        Object.entries(merged).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)),
    );
});

const getCount = (cards: App.Data.Front.CardData[]) => cards.reduce((sum, c) => sum + c.quantity, 0);
const maindeckCount = computed(() => Object.values(props.maindeck).flat().reduce((sum, c) => sum + c.quantity, 0));
const sideboardCount = computed(() => props.sideboard.reduce((sum, c) => sum + c.quantity, 0));
</script>

<template>
    <div class="relative flex h-screen flex-col bg-background text-foreground">
        <div class="shrink-0 p-4" style="-webkit-app-region: drag">
            <h1 class="text-xl font-bold leading-tight">{{ deckName }}</h1>
            <p class="text-sm text-muted-foreground">{{ format }}</p>
        </div>

        <div class="deck-scroll flex-1 space-y-4 overflow-y-auto px-4 pb-4">
            <!-- Main Deck -->
            <div>
                <h2 class="mb-1.5 text-sm font-bold uppercase tracking-wider text-muted-foreground">
                    Main Deck ({{ maindeckCount }})
                </h2>
                <div v-for="(cards, type) in groupedMaindeck" :key="type" class="mb-3">
                    <h3 class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground/60">
                        {{ type }} ({{ getCount(cards) }})
                    </h3>
                    <div
                        v-for="card in cards"
                        :key="card.mtgoId ?? card.name"
                        :style="borderStyle(card.identity)"
                        class="flex items-center justify-between py-1 pl-2.5 pr-1.5 text-sm"
                        @mouseenter="onCardEnter(card, $event)"
                        @mouseleave="onCardLeave"
                    >
                        <span class="truncate">
                            <span class="font-semibold tabular-nums">{{ card.quantity }}</span>
                            {{ card.name }}
                        </span>
                        <ManaSymbols :symbols="card.identity" class="shrink-0" />
                    </div>
                </div>
            </div>

            <!-- Sideboard -->
            <div v-if="sideboard.length">
                <h2 class="mb-1.5 text-sm font-bold uppercase tracking-wider text-muted-foreground">
                    Sideboard ({{ sideboardCount }})
                </h2>
                <div
                    v-for="card in sideboard"
                    :key="card.mtgoId ?? card.name"
                    :style="borderStyle(card.identity)"
                    class="flex items-center justify-between py-1 pl-2.5 pr-1.5 text-sm"
                    @mouseenter="onCardEnter(card, $event)"
                    @mouseleave="onCardLeave"
                >
                    <span class="truncate">
                        <span class="font-semibold tabular-nums">{{ card.quantity }}</span>
                        {{ card.name }}
                    </span>
                    <ManaSymbols :symbols="card.identity" class="shrink-0" />
                </div>
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
    </div>
</template>

<style scoped>
.fade-enter-active { transition: opacity 0.1s ease; }
.fade-leave-active { transition: opacity 0.05s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.deck-scroll::-webkit-scrollbar { width: 6px; }
.deck-scroll::-webkit-scrollbar-track { background: transparent; }
.deck-scroll::-webkit-scrollbar-thumb {
    background: #4a5568;
    border-radius: 3px;
}
.deck-scroll::-webkit-scrollbar-thumb:hover {
    background: #5a6a80;
}
</style>
