<script setup lang="ts">
import { computed, ref } from 'vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Eye } from 'lucide-vue-next';

type SeenCard = {
    name: string;
    image: string | null;
    type: string | null;
    identity: string | null;
    quantity: number;
};

type GameInput = {
    number: number;
    won: boolean;
    opponentCardsSeen: SeenCard[];
};

type AggregatedReveal = {
    name: string;
    image: string | null;
    type: string | null;
    identity: string | null;
    games: number[];
    quantity: number;
};

const props = defineProps<{
    games: GameInput[];
    opponentName: string;
}>();

const filter = ref<'all' | number>('all');
const hovered = ref<AggregatedReveal | null>(null);
const previewTop = ref(0);

const allGameNumbers = computed(() => props.games.map((g) => g.number));

const CANONICAL_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'] as const;
const TYPE_ORDER = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

function normalizeType(raw: string | null): string {
    if (!raw) return 'Other';
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}

const aggregated = computed<AggregatedReveal[]>(() => {
    const map = new Map<string, AggregatedReveal>();

    for (const game of props.games) {
        if (filter.value !== 'all' && game.number !== filter.value) {
            continue;
        }

        for (const card of game.opponentCardsSeen) {
            const key = card.name;
            if (!map.has(key)) {
                map.set(key, {
                    name: card.name,
                    image: card.image,
                    type: card.type,
                    identity: card.identity,
                    games: [],
                    quantity: 0,
                });
            }
            const entry = map.get(key)!;
            if (!entry.games.includes(game.number)) {
                entry.games.push(game.number);
            }
            entry.quantity = Math.max(entry.quantity, card.quantity);
        }
    }

    return [...map.values()];
});

const grouped = computed(() => {
    const merged: Record<string, AggregatedReveal[]> = {};
    for (const reveal of aggregated.value) {
        const key = normalizeType(reveal.type);
        (merged[key] ??= []).push(reveal);
    }
    for (const list of Object.values(merged)) {
        list.sort((a, b) => a.name.localeCompare(b.name));
    }
    return Object.fromEntries(
        Object.entries(merged).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)),
    );
});

const groupCount = (cards: AggregatedReveal[]) => cards.reduce((sum, c) => sum + c.quantity, 0);

const totalUnique = computed(() => aggregated.value.length);

const COLOR_MAP: Record<string, string> = {
    W: '#F8F6D8',
    U: '#C1D7E9',
    B: '#BAB1AB',
    R: '#E49977',
    G: '#A3C095',
};

function borderStyle(identity: string | null): Record<string, string> {
    const colors = (identity ?? '')
        .split(',')
        .map((c) => COLOR_MAP[c.trim()])
        .filter(Boolean);

    if (colors.length === 0) {
        return { borderLeftColor: 'transparent', borderLeftWidth: '3px', borderLeftStyle: 'solid' };
    }
    if (colors.length === 1) {
        return { borderLeftColor: colors[0], borderLeftWidth: '3px', borderLeftStyle: 'solid' };
    }
    const pct = 100 / colors.length;
    const stops = colors.map((c, i) => `${c} ${i * pct}% ${(i + 1) * pct}%`).join(', ');
    return {
        borderImage: `linear-gradient(to bottom, ${stops}) 1`,
        borderLeftWidth: '3px',
        borderLeftStyle: 'solid',
    };
}

function onCardEnter(card: AggregatedReveal, event: MouseEvent) {
    if (!card.image) return;
    hovered.value = card;
    const rowTop = (event.currentTarget as HTMLElement).getBoundingClientRect().top;
    const maxTop = window.innerHeight - 280;
    previewTop.value = Math.max(8, Math.min(rowTop, maxTop));
}

function onCardLeave() {
    hovered.value = null;
}
</script>

<template>
    <aside class="flex flex-col overflow-hidden rounded-md border bg-card">
        <header class="flex items-center justify-between gap-2 border-b px-3 py-2.5">
            <span class="inline-flex items-center gap-1.5 text-xs font-bold tracking-wider uppercase text-muted-foreground">
                <Eye :size="12" />
                Opponent reveals
            </span>
            <span class="rounded-full bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground tabular-nums">
                {{ totalUnique }}
            </span>
        </header>

        <div v-if="games.length > 1" class="flex gap-1 border-b px-2 py-1.5">
            <button
                type="button"
                class="rounded border px-1.5 py-0.5 font-mono text-[10px] tracking-widest uppercase transition-colors"
                :class="filter === 'all' ? 'border-border bg-muted text-foreground' : 'border-transparent text-muted-foreground hover:bg-muted/50'"
                @click="filter = 'all'"
            >
                All
            </button>
            <button
                v-for="n in allGameNumbers"
                :key="n"
                type="button"
                class="rounded border px-1.5 py-0.5 font-mono text-[10px] tracking-widest uppercase transition-colors"
                :class="filter === n ? 'border-border bg-muted text-foreground' : 'border-transparent text-muted-foreground hover:bg-muted/50'"
                @click="filter = n"
            >
                G{{ n }}
            </button>
        </div>

        <div class="flex max-h-[60vh] flex-col gap-3 overflow-auto px-3 py-3">
            <section v-for="(cards, type) in grouped" :key="`group_${type}`">
                <h3 class="mb-0.5 text-[10px] font-semibold tracking-wider uppercase text-muted-foreground/60">
                    {{ type }} ({{ groupCount(cards) }})
                </h3>
                <div
                    v-for="card in cards"
                    :key="card.name"
                    :style="borderStyle(card.identity)"
                    class="flex items-center justify-between gap-2 py-0.5 pr-1 pl-2 text-xs"
                    @mouseenter="onCardEnter(card, $event)"
                    @mouseleave="onCardLeave"
                >
                    <span class="min-w-0 truncate">
                        <span class="font-semibold tabular-nums">{{ card.quantity }}</span>
                        {{ card.name }}
                    </span>
                    <div class="flex shrink-0 items-center gap-1">
                        <ManaSymbols
                            v-if="card.identity"
                            :symbols="card.identity"
                            class="[&_svg]:size-3"
                        />
                        <span
                            v-for="g in card.games"
                            :key="g"
                            class="rounded bg-muted px-1 py-px font-mono text-[9px] text-muted-foreground"
                        >
                            G{{ g }}
                        </span>
                    </div>
                </div>
            </section>

            <p v-if="aggregated.length === 0" class="px-3 py-6 text-center text-xs text-muted-foreground italic">
                No cards revealed in this game.
            </p>
        </div>

        <Transition name="fade">
            <div
                v-if="hovered?.image"
                class="pointer-events-none fixed right-2 z-50"
                :style="{ top: `${previewTop}px` }"
            >
                <img
                    :src="hovered.image"
                    :alt="hovered.name"
                    class="w-[200px] rounded-lg shadow-xl ring-1 ring-border"
                />
            </div>
        </Transition>
    </aside>
</template>

<style scoped>
.fade-enter-active { transition: opacity 0.1s ease; }
.fade-leave-active { transition: opacity 0.05s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
