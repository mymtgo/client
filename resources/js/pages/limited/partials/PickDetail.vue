<script setup lang="ts">
import CardThumb from '@/components/limited/CardThumb.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ReservationTrail from '@/pages/limited/partials/ReservationTrail.vue';
import { cardFor, formatSeconds, type DraftPick, type LimitedCards, type SeenWheelFact } from '@/types/limited';
import { ArrowLeft, ArrowRight, Clock, Eye } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pick: DraftPick;
    cards: LimitedCards;
    seenWheel: Record<string, SeenWheelFact>;
    hasPrev: boolean;
    hasNext: boolean;
}>();
const emit = defineEmits<{ prev: []; next: []; selectCard: [catalogId: number] }>();

type Tile = {
    key: string;
    card: App.Data.Front.LimitedCardData;
    picked: boolean;
    wheeled: boolean;
    gone: boolean;
    fact: SeenWheelFact | undefined;
};

const tiles = computed<Tile[]>(() =>
    props.pick.available.map((id, index) => ({
        key: `${id}-${index}`,
        card: cardFor(props.cards, id),
        picked: id === props.pick.pickedCatalogId,
        wheeled: props.pick.wheeledIds.includes(id),
        gone: props.pick.wheelReturnOrdinal !== null && props.pick.takenIds.includes(id),
        fact: props.seenWheel[String(id)],
    })),
);

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
    <div class="flex flex-col gap-4 rounded-lg border border-black/60 bg-card p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold">Pack {{ pick.packNumber }} · Pick {{ pick.pickNumber }}</h2>
            <Badge variant="secondary">{{ pick.available.length }} cards</Badge>
            <Badge v-if="pick.direction !== null" variant="outline">
                <component :is="pick.direction === 1 ? ArrowRight : ArrowLeft" class="size-3" /> passed {{ pick.direction === 1 ? 'right' : 'left' }}
            </Badge>
            <Badge v-if="pick.elapsedSeconds !== null" variant="outline">
                <Clock class="size-3" /> {{ pick.elapsedSeconds }}s<span v-if="margin"> · {{ margin }}</span>
            </Badge>
            <Badge v-if="pick.indecisive" variant="outline" class="border-amber-500/40 text-amber-300">
                <Eye class="size-3" /> {{ pick.reservations.length }} reservations
            </Badge>
            <div class="ml-auto flex items-center gap-1">
                <Button variant="ghost" size="sm" :disabled="!hasPrev" @click="emit('prev')"><ArrowLeft class="size-3.5" /> Prev</Button>
                <Button variant="ghost" size="sm" :disabled="!hasNext" @click="emit('next')">Next <ArrowRight class="size-3.5" /></Button>
            </div>
        </div>

        <div class="grid grid-cols-7 gap-3">
            <button
                v-for="tile in tiles"
                :key="tile.key"
                type="button"
                class="flex min-w-0 flex-col items-center gap-1.5 rounded-md text-left"
                :title="tile.card.name"
                @click="emit('selectCard', tile.card.catalogId)"
            >
                <div class="w-full rounded-md" :class="tile.wheeled && !tile.picked ? 'ring-2 ring-muted-foreground/40' : ''">
                    <CardThumb :card="tile.card" :fluid="true" :highlighted="tile.picked" :dimmed="tile.gone">
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
                            passed ×{{ tile.fact.passed_count }}
                        </span>
                    </CardThumb>
                </div>
                <Badge v-if="tile.picked" variant="default">Pick</Badge>
                <Badge v-else-if="tile.wheeled" variant="secondary" class="text-muted-foreground">Wheeled</Badge>
                <span v-else class="h-5" aria-hidden="true" />
            </button>
        </div>

        <ReservationTrail :pick="pick" :cards="cards" />
        <p v-if="wheelSummary" class="text-xs text-muted-foreground">{{ wheelSummary }}</p>
    </div>
</template>
