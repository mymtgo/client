<script setup lang="ts">
import CardThumb from '@/components/limited/CardThumb.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger } from '@/components/ui/select';
import ReservationTrail from '@/pages/limited/partials/ReservationTrail.vue';
import { cardFor, formatSeconds, type DraftPick, type LimitedCards, type SeenWheelFact } from '@/types/limited';
import { ArrowLeft, ArrowRight, Clock, Eye, Layers } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pick: DraftPick;
    picks: DraftPick[];
    cards: LimitedCards;
    seenWheel: Record<string, SeenWheelFact>;
    /** Cards the pack opened with, so the grid keeps its height as picks shrink it. */
    packSize: number;
}>();
const emit = defineEmits<{ select: [ordinal: number]; selectCard: [catalogId: number] }>();

type Tile = {
    key: string;
    card: App.Data.Front.LimitedCardData;
    picked: boolean;
    wheeled: boolean;
    gone: boolean;
    fact: SeenWheelFact | undefined;
};

/**
 * The pick leads the grid so the answer reads first; everything else holds the
 * order the pack arrived in. Pack order carries no meaning in MTGO, and the grid
 * reflows anyway as cards leave, so nothing is lost by promoting the pick.
 */
const tiles = computed<Tile[]>(() => {
    const built = props.pick.available.map((id, index) => ({
        key: `${id}-${index}`,
        card: cardFor(props.cards, id),
        picked: id === props.pick.pickedCatalogId,
        wheeled: props.pick.wheeledIds.includes(id),
        gone: props.pick.wheelReturnOrdinal !== null && props.pick.takenIds.includes(id),
        fact: props.seenWheel[String(id)],
    }));
    return [...built.filter((tile) => tile.picked), ...built.filter((tile) => !tile.picked)];
});

/** Pad every pick out to the pack's opening size so the grid never loses a row mid-draft. */
const fillerCount = computed(() => Math.max(0, props.packSize - tiles.value.length));

const index = computed(() => props.picks.findIndex((candidate) => candidate.ordinal === props.pick.ordinal));
const hasPrev = computed(() => index.value > 0);
const hasNext = computed(() => index.value >= 0 && index.value < props.picks.length - 1);

function step(offset: number) {
    const target = props.picks[index.value + offset];
    if (target) {
        emit('select', target.ordinal);
    }
}

const packs = computed<[number, DraftPick[]][]>(() => {
    const groups = new Map<number, DraftPick[]>();
    for (const pick of props.picks) {
        const list = groups.get(pick.packNumber) ?? [];
        list.push(pick);
        groups.set(pick.packNumber, list);
    }
    return [...groups.entries()].sort(([a], [b]) => a - b);
});

const margin = computed(() =>
    props.pick.marginSeconds === null
        ? null
        : `${formatSeconds(Math.abs(props.pick.marginSeconds))} ${props.pick.marginSeconds >= 0 ? 'spare' : 'over'}`,
);

const wheelSummary = computed(() => {
    if (props.pick.wheelReturnOrdinal === null) {
        return null;
    }
    const name = (id: number) => cardFor(props.cards, id).name;
    const wheeled = props.pick.wheeledIds.map((id) => (props.seenWheel[String(id)]?.wheeled_to_me ? `${name(id)} (you took it)` : name(id)));
    const gone = props.pick.takenIds.map(name);
    return `Wheeled at pick ${props.pick.wheelReturnOrdinal}: ${wheeled.length ? wheeled.join(', ') : 'nothing'}. Gone: ${gone.length ? gone.join(', ') : 'nothing'}.`;
});
</script>

<template>
    <div class="flex flex-col gap-4 rounded-lg p-4">
        <div class="flex flex-wrap items-center gap-2">
            <Select :model-value="String(pick.ordinal)" @update:model-value="(value) => emit('select', Number(value))">
                <SelectTrigger size="sm" class="font-semibold" aria-label="Jump to a pick">
                    <span>Pack {{ pick.packNumber }} &middot; Pick {{ pick.pickNumber }}</span>
                </SelectTrigger>
                <SelectContent class="max-h-80">
                    <SelectGroup v-for="[pack, list] in packs" :key="pack">
                        <SelectLabel>Pack {{ pack }}</SelectLabel>
                        <SelectItem v-for="option in list" :key="option.ordinal" :value="String(option.ordinal)">
                            <span class="tabular-nums">Pick {{ option.pickNumber }}</span>
                            <span class="truncate text-muted-foreground">
                                {{ option.pickedCatalogId !== null ? cardFor(cards, option.pickedCatalogId).name : 'no pick' }}
                            </span>
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>

            <div class="flex items-center gap-1">
                <Button variant="ghost" size="icon-sm" :disabled="!hasPrev" aria-label="Previous pick" @click="step(-1)">
                    <ArrowLeft class="size-3.5" />
                </Button>
                <Button variant="ghost" size="icon-sm" :disabled="!hasNext" aria-label="Next pick" @click="step(1)">
                    <ArrowRight class="size-3.5" />
                </Button>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <Badge variant="outline" class="text-muted-foreground"> <Layers class="size-3" /> {{ pick.available.length }} cards </Badge>
                <Badge v-if="pick.direction !== null" variant="outline" class="text-muted-foreground">
                    <component :is="pick.direction === 1 ? ArrowRight : ArrowLeft" class="size-3" /> passed
                    {{ pick.direction === 1 ? 'right' : 'left' }}
                </Badge>
                <Badge v-if="pick.elapsedSeconds !== null" variant="outline" class="text-muted-foreground">
                    <Clock class="size-3" /> {{ pick.elapsedSeconds }}s<span v-if="margin"> &middot; {{ margin }}</span>
                </Badge>
                <Badge v-if="pick.indecisive" variant="outline" class="border-amber-500/30 text-amber-300">
                    <Eye class="size-3" /> {{ pick.reservations.length }} reservations
                </Badge>
            </div>
        </div>

        <Card class="gap-0 p-4">
            <CardContent class="p-0">
                <div class="grid grid-cols-7 gap-3">
                    <button
                        v-for="tile in tiles"
                        :key="tile.key"
                        type="button"
                        class="min-w-0 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        :title="tile.card.name"
                        @click="emit('selectCard', tile.card.catalogId)"
                    >
                        <CardThumb
                            :card="tile.card"
                            :fluid="true"
                            :highlighted="tile.picked"
                            :dimmed="tile.gone"
                            :class="tile.wheeled && !tile.picked ? 'ring-1 ring-muted-foreground/30' : ''"
                        >
                            <span
                                v-if="tile.fact && tile.fact.seen_count > 1 && !tile.picked && !tile.wheeled"
                                class="absolute right-1 bottom-1 rounded bg-black/70 px-1 text-[10px]"
                            >
                                seen {{ tile.fact.seen_count }}
                            </span>
                            <span
                                v-if="tile.fact && tile.fact.passed_count > 1 && !tile.picked && !tile.wheeled"
                                class="absolute bottom-1 left-1 rounded bg-black/70 px-1 text-[10px] text-muted-foreground"
                            >
                                passed &times;{{ tile.fact.passed_count }}
                            </span>
                            <Badge v-if="tile.picked" variant="default" class="absolute bottom-1 left-1">Pick</Badge>
                            <Badge v-else-if="tile.wheeled" variant="outline" class="absolute bottom-1 left-1 bg-background/85 text-muted-foreground">
                                Wheeled
                            </Badge>
                        </CardThumb>
                    </button>
                    <div v-for="i in fillerCount" :key="`filler-${i}`" class="aspect-[5/7] rounded-md border border-dashed border-border/40" />
                </div>
            </CardContent>
        </Card>

        <ReservationTrail :pick="pick" :cards="cards" />
        <p v-if="wheelSummary" class="text-xs text-muted-foreground">{{ wheelSummary }}</p>
    </div>
</template>
