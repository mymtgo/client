<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import type { VersionStats, VersionDecklist } from '@/types/decks';
import { computed, ref } from 'vue';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
    versionDecklists: Record<string, VersionDecklist>;
}>();

const selectedVersionKey = ref<string>(String(props.currentVersionId ?? ''));

const activeDecklist = computed((): VersionDecklist => {
    return props.versionDecklists?.[selectedVersionKey.value] ?? { maindeck: props.maindeck, sideboard: props.sideboard };
});

type ColorStat = { color: string; label: string; count: number; total: number; percentage: number };

const colorDistribution = computed((): ColorStat[] => {
    const dl = activeDecklist.value;
    const nonLandCards = Object.entries(dl.maindeck)
        .filter(([type]) => !type.includes('Land'))
        .flatMap(([, cards]) => cards);

    const total = nonLandCards.reduce((sum, c) => sum + c.quantity, 0);
    if (total === 0) return [];

    const colors = [
        { color: 'W', label: 'White' },
        { color: 'U', label: 'Blue' },
        { color: 'B', label: 'Black' },
        { color: 'R', label: 'Red' },
        { color: 'G', label: 'Green' },
        { color: 'C', label: 'Colorless' },
    ];

    return colors.map(({ color, label }) => {
        const count = nonLandCards
            .filter((c) => {
                if (color === 'C') return !c.identity || c.identity === '' || c.identity === 'C';
                return c.identity?.split(',').includes(color);
            })
            .reduce((sum, c) => sum + c.quantity, 0);

        return { color, label, count, total, percentage: Math.round((count / total) * 100) };
    });
});

const visibleColorDistribution = computed(() => colorDistribution.value.filter(s => s.count > 0));

type CmcBucket = { cmc: string; count: number };

const cmcDistribution = computed((): CmcBucket[] => {
    const dl = activeDecklist.value;
    const nonLandCards = Object.entries(dl.maindeck)
        .filter(([type]) => !type.includes('Land'))
        .flatMap(([, cards]) => cards);

    const buckets = new Map<number, number>();
    for (const card of nonLandCards) {
        const cmc = Math.floor(card.cmc ?? 0);
        buckets.set(cmc, (buckets.get(cmc) ?? 0) + card.quantity);
    }

    const sorted = [...buckets.entries()].sort((a, b) => a[0] - b[0]);

    // Cap at 7+ bucket
    const result = new Map<string, number>();
    for (const [cmc, count] of sorted) {
        const key = cmc >= 7 ? '7+' : String(cmc);
        result.set(key, (result.get(key) ?? 0) + count);
    }

    return [...result.entries()].map(([cmc, count]) => ({ cmc, count }));
});

const cmcMax = computed(() => Math.max(...cmcDistribution.value.map(d => d.count), 1));

const decklistOrgUrl = computed(() => {
    const dl = activeDecklist.value;
    const mainCards = Object.values(dl.maindeck).flat().map((c) => `${c.quantity} ${c.name}`).join('\n');
    const sideCards = dl.sideboard.map((c) => `${c.quantity} ${c.name}`).join('\n');
    const params = new URLSearchParams({
        deckmain: mainCards,
        deckside: sideCards,
        eventformat: props.deck.format,
    });
    return `https://decklist.org/?${params.toString()}`;
});
</script>

<template>
    <div class="p-3 lg:p-4">
        <div class="mb-4 flex items-center justify-end">
            <a :href="decklistOrgUrl" target="_blank" class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground">
                Deck Registration
            </a>
        </div>
        <div class="grid grid-cols-4 gap-4">
            <div class="col-span-3">
                <DeckList :maindeck="activeDecklist.maindeck" :sideboard="activeDecklist.sideboard" />
            </div>
            <div class="col-span-1 flex flex-col gap-4">
                <!-- CMC Distribution -->
                <div v-if="cmcDistribution.length" class="flex flex-col gap-2">
                    <h3 class="text-sm font-medium text-muted-foreground">Mana Curve <span class="text-xs font-normal">(maindeck, nonland)</span></h3>
                    <div class="flex items-end gap-1" style="height: 120px;">
                        <div
                            v-for="bucket in cmcDistribution"
                            :key="bucket.cmc"
                            class="flex flex-1 flex-col items-center gap-1"
                        >
                            <span class="text-xs tabular-nums text-muted-foreground">{{ bucket.count }}</span>
                            <div
                                class="w-full rounded-t bg-primary/80 transition-all"
                                :style="{ height: `${(bucket.count / cmcMax) * 90}px` }"
                            />
                            <span class="text-xs tabular-nums text-muted-foreground">{{ bucket.cmc }}</span>
                        </div>
                    </div>
                </div>

                <!-- Color Distribution -->
                <div v-if="visibleColorDistribution.length" class="flex flex-col gap-2">
                    <h3 class="text-sm font-medium text-muted-foreground">Color Distribution <span class="text-xs font-normal">(maindeck, nonland)</span></h3>
                    <div class="grid grid-cols-2 gap-2">
                        <div
                            v-for="stat in visibleColorDistribution"
                            :key="stat.color"
                            class="flex items-center gap-3 rounded-md border border-border bg-muted/30 px-3 py-2.5"
                        >
                            <ManaSymbols :symbols="stat.color" class="shrink-0" />
                            <div class="flex flex-1 flex-col">
                                <span class="text-sm font-semibold tabular-nums">{{ stat.percentage }}%</span>
                                <span class="text-xs text-muted-foreground">{{ stat.count }} of {{ stat.total }} cards</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
