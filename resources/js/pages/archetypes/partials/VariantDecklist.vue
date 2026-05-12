<script setup lang="ts">
import { computed, ref } from 'vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Button } from '@/components/ui/button';
import { Download, Loader2, MoveRight } from 'lucide-vue-next';
import ExportDekController from '@/actions/App/Http/Controllers/Archetypes/ExportDekController';
import { useToast } from '@/composables/useToast';
import ReassignVariantDialog from './ReassignVariantDialog.vue';

interface Props {
    archetypeId: number;
    archetypeName: string;
    variantLabel: string;
    deck: App.Data.Front.ArchetypeDeckData;
}

const props = defineProps<Props>();

const exporting = ref<boolean>(false);
const reassignOpen = ref<boolean>(false);
const { add: addToast } = useToast();

async function downloadDek(): Promise<void> {
    if (exporting.value) {
        return;
    }
    exporting.value = true;

    try {
        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        const response = await fetch(
            ExportDekController.url({ archetype: props.archetypeId }),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ archetype_deck_id: props.deck.id }),
            },
        );

        if (!response.ok) {
            addToast({ type: 'error', title: 'Export failed', message: 'Could not save deck file' });
            return;
        }

        const result = await response.json();
        if (result.success) {
            addToast({ type: 'success', title: 'Saved deck file', message: result.path });
        } else if (!result.cancelled) {
            addToast({ type: 'error', title: 'Export failed', message: result.message ?? 'Could not save deck file' });
        }
    } catch {
        addToast({ type: 'error', title: 'Export failed', message: 'Could not save deck file' });
    } finally {
        exporting.value = false;
    }
}

type Card = App.Data.Front.CardData;

interface ColorStat {
    color: string;
    label: string;
    count: number;
    total: number;
    percentage: number;
}

interface CmcBucket {
    cmc: string;
    count: number;
}

const maindeck = computed<Record<string, Card[]>>(() => {
    const out: Record<string, Card[]> = {};
    for (const card of props.deck.cards) {
        if (card.sideboard) {
            continue;
        }
        const type = card.type ?? 'Other';
        (out[type] ??= []).push(card);
    }

    return out;
});

const sideboard = computed<Card[]>(() =>
    props.deck.cards.filter((c) => c.sideboard),
);

const nonLandMainCards = computed<Card[]>(() =>
    Object.entries(maindeck.value)
        .filter(([type]) => !type.includes('Land'))
        .flatMap(([, cards]) => cards),
);

const colorDistribution = computed<ColorStat[]>(() => {
    const total = nonLandMainCards.value.reduce((sum, c) => sum + c.quantity, 0);
    if (total === 0) {
        return [];
    }

    const colors: { color: string; label: string }[] = [
        { color: 'W', label: 'White' },
        { color: 'U', label: 'Blue' },
        { color: 'B', label: 'Black' },
        { color: 'R', label: 'Red' },
        { color: 'G', label: 'Green' },
        { color: 'C', label: 'Colorless' },
    ];

    return colors.map(({ color, label }) => {
        const count = nonLandMainCards.value
            .filter((c) => {
                if (color === 'C') {
                    return !c.identity || c.identity === '' || c.identity === 'C';
                }
                return c.identity?.split(',').includes(color);
            })
            .reduce((sum, c) => sum + c.quantity, 0);

        return {
            color,
            label,
            count,
            total,
            percentage: Math.round((count / total) * 100),
        };
    });
});

const visibleColorDistribution = computed<ColorStat[]>(() =>
    colorDistribution.value.filter((s) => s.count > 0),
);

const cmcDistribution = computed<CmcBucket[]>(() => {
    const buckets = new Map<number, number>();
    for (const card of nonLandMainCards.value) {
        const cmc = Math.floor(card.cmc ?? 0);
        buckets.set(cmc, (buckets.get(cmc) ?? 0) + card.quantity);
    }

    const sorted = [...buckets.entries()].sort((a, b) => a[0] - b[0]);

    const result = new Map<string, number>();
    for (const [cmc, count] of sorted) {
        const key = cmc >= 7 ? '7+' : String(cmc);
        result.set(key, (result.get(key) ?? 0) + count);
    }

    return [...result.entries()].map(([cmc, count]) => ({ cmc, count }));
});

const cmcMax = computed<number>(() =>
    Math.max(...cmcDistribution.value.map((d) => d.count), 1),
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-end gap-2">
            <Button
                variant="outline"
                size="sm"
                @click="reassignOpen = true"
            >
                <MoveRight class="mr-1.5 size-3.5" />
                Move to archetype
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="exporting"
                @click="downloadDek"
            >
                <Loader2 v-if="exporting" class="mr-1.5 size-3.5 animate-spin" />
                <Download v-else class="mr-1.5 size-3.5" />
                {{ exporting ? 'Saving…' : 'Download .dek' }}
            </Button>
        </div>

        <ReassignVariantDialog
            v-model:open="reassignOpen"
            :archetype-id="props.archetypeId"
            :archetype-name="props.archetypeName"
            :deck-id="props.deck.id"
            :variant-label="props.variantLabel"
        />
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
        <div class="xl:col-span-3">
            <DeckList :maindeck="maindeck" :sideboard="sideboard" />
        </div>

        <aside class="flex flex-col gap-4 xl:col-span-1">
            <div v-if="cmcDistribution.length > 0" class="flex flex-col gap-2">
                <h3 class="text-sm font-medium text-muted-foreground">
                    Mana Curve
                    <span class="text-xs font-normal">(maindeck, nonland)</span>
                </h3>
                <div class="flex items-end gap-1" style="height: 120px;">
                    <div
                        v-for="bucket in cmcDistribution"
                        :key="bucket.cmc"
                        class="flex flex-1 flex-col items-center gap-1"
                    >
                        <span class="text-xs tabular-nums text-muted-foreground">
                            {{ bucket.count }}
                        </span>
                        <div
                            class="w-full rounded-t bg-primary/80 transition-all"
                            :style="{ height: `${(bucket.count / cmcMax) * 90}px` }"
                        />
                        <span class="text-xs tabular-nums text-muted-foreground">
                            {{ bucket.cmc }}
                        </span>
                    </div>
                </div>
            </div>

            <div v-if="visibleColorDistribution.length > 0" class="flex flex-col gap-2">
                <h3 class="text-sm font-medium text-muted-foreground">
                    Color Distribution
                    <span class="text-xs font-normal">(maindeck, nonland)</span>
                </h3>
                <div class="grid grid-cols-2 gap-2">
                    <div
                        v-for="stat in visibleColorDistribution"
                        :key="stat.color"
                        class="flex items-center gap-3 rounded-md border border-border bg-muted/30 px-3 py-2.5"
                    >
                        <ManaSymbols :symbols="stat.color" class="shrink-0" />
                        <div class="flex flex-1 flex-col">
                            <span class="text-sm font-semibold tabular-nums">
                                {{ stat.percentage }}%
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ stat.count }} of {{ stat.total }} cards
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        </div>
    </div>
</template>
