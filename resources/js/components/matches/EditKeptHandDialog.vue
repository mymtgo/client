<script setup lang="ts">
import UpdateHandController from '@/actions/App/Http/Controllers/Games/UpdateHandController';
import CardTilePill from '@/components/matches/CardTilePill.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { DeckCardOption, GameDetail } from '@/types/matches';
import { useForm } from '@inertiajs/vue3';
import { Minus, Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    game: GameDetail;
    /** Effective maindeck for this game: registered mains with sideboard changes applied. */
    mains: DeckCardOption[];
}>();

const HAND_SIZE = 7;

type Payload = {
    mulligan_count: number;
    kept_hand: number[];
    bottomed: number[];
    mulliganed_hands: number[][];
};

const open = ref(false);
const mulligans = ref(0);
/** One seven card hand per mulligan (may be empty when not remembered), then the final hand last. */
const hands = ref<number[][]>([[]]);
/** Indexes into the final hand that were put on the bottom. */
const bottomedIndexes = ref<Set<number>>(new Set());
/** Which hand is being edited: 0..mulligans-1 are put-back hands, mulligans is the final hand. */
const stage = ref(0);

const form = useForm<Payload>({
    mulligan_count: 0,
    kept_hand: [],
    bottomed: [],
    mulliganed_hands: [],
});

const finalStage = computed(() => mulligans.value);
const editingFinal = computed(() => stage.value === finalStage.value);
const currentHand = computed(() => hands.value[stage.value] ?? []);
const currentFull = computed(() => currentHand.value.length >= HAND_SIZE);

const stages = computed(() =>
    Array.from({ length: mulligans.value + 1 }, (_, i) => ({
        index: i,
        label: i === mulligans.value ? 'Final hand' : `Put back ${i + 1}`,
        count: hands.value[i]?.length ?? 0,
    })),
);

const inCurrentHand = computed(() => {
    const counts = new Map<number, number>();
    for (const id of currentHand.value) counts.set(id, (counts.get(id) ?? 0) + 1);
    return counts;
});

const currentCards = computed(() => currentHand.value.map((id) => props.mains.find((c) => c.mtgoId === id)).filter((c): c is DeckCardOption => !!c));

const finalHand = computed(() => hands.value[finalStage.value] ?? []);
const bottomedCount = computed(() => bottomedIndexes.value.size);

const mulliganHandsOk = computed(() => hands.value.slice(0, finalStage.value).every((h) => h.length === 0 || h.length === HAND_SIZE));
const finalOk = computed(() => finalHand.value.length === HAND_SIZE && bottomedCount.value === mulligans.value);
const canSave = computed(() => finalOk.value && mulliganHandsOk.value);

const hint = computed(() => {
    if (finalHand.value.length !== HAND_SIZE) return `Final hand needs ${HAND_SIZE} cards.`;
    if (bottomedCount.value !== mulligans.value) {
        return mulligans.value === 1 ? 'Mark the card you bottomed.' : `Mark the ${mulligans.value} cards you bottomed.`;
    }
    if (!mulliganHandsOk.value) return 'A put-back hand is seven cards, or leave it empty if you do not remember it.';
    return null;
});

function remaining(card: DeckCardOption): number {
    return card.quantity - (inCurrentHand.value.get(card.mtgoId) ?? 0);
}

function add(card: DeckCardOption): void {
    if (currentFull.value || remaining(card) <= 0) return;
    hands.value[stage.value] = [...currentHand.value, card.mtgoId];
}

function removeAt(index: number): void {
    hands.value[stage.value] = currentHand.value.filter((_, i) => i !== index);
    if (editingFinal.value) {
        const next = new Set<number>();
        for (const i of bottomedIndexes.value) {
            if (i < index) next.add(i);
            else if (i > index) next.add(i - 1);
        }
        bottomedIndexes.value = next;
    }
}

function toggleBottomed(index: number): void {
    if (!editingFinal.value || mulligans.value === 0) return;
    const next = new Set(bottomedIndexes.value);
    if (next.has(index)) {
        next.delete(index);
    } else if (next.size < mulligans.value) {
        next.add(index);
    }
    bottomedIndexes.value = next;
}

function isBottomed(index: number): boolean {
    return editingFinal.value && bottomedIndexes.value.has(index);
}

function clearCurrent(): void {
    hands.value[stage.value] = [];
    if (editingFinal.value) bottomedIndexes.value = new Set();
}

function setMulligans(value: number): void {
    const next = Math.min(6, Math.max(0, value));
    const final = hands.value[finalStage.value] ?? [];
    const putBack = hands.value.slice(0, finalStage.value);
    while (putBack.length < next) putBack.push([]);
    hands.value = [...putBack.slice(0, next), final];
    mulligans.value = next;
    if (bottomedIndexes.value.size > next) {
        bottomedIndexes.value = new Set([...bottomedIndexes.value].slice(0, next));
    }
    if (stage.value > next) stage.value = next;
}

function openDialog(): void {
    form.clearErrors();
    mulligans.value = props.game.localMulligans;
    const final = props.game.keptHand.map((c) => c.mtgoId);
    const bottomed = new Set<number>();
    props.game.keptHand.forEach((c, i) => {
        if (c.bottomed) bottomed.add(i);
    });
    const putBack = props.game.mulliganedHands.map((hand) => hand.map((c) => c.mtgoId));
    while (putBack.length < mulligans.value) putBack.push([]);
    hands.value = [...putBack.slice(0, mulligans.value), final];
    bottomedIndexes.value = bottomed;
    stage.value = mulligans.value;
    open.value = true;
}

function submit(payload?: Payload): void {
    if (payload) {
        Object.assign(form, payload);
    } else {
        form.mulligan_count = mulligans.value;
        form.kept_hand = finalHand.value.filter((_, i) => !bottomedIndexes.value.has(i));
        form.bottomed = finalHand.value.filter((_, i) => bottomedIndexes.value.has(i));
        form.mulliganed_hands = hands.value.slice(0, finalStage.value);
    }
    form.submit(UpdateHandController({ game: props.game.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

const firstError = computed(() => Object.values(form.errors)[0] ?? null);

defineExpose({ open: openDialog });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[85vh] flex-col gap-4 overflow-hidden sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Game {{ game.number }} opening hand</DialogTitle>
                <DialogDescription>
                    Seven cards drawn. After a mulligan, pick the seven you saw and mark what you bottomed. Put-back hands are optional.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center gap-3 text-sm">
                <span class="text-muted-foreground">Mulligans</span>
                <div class="inline-flex items-center gap-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        class="size-7"
                        :disabled="mulligans === 0"
                        @click="setMulligans(mulligans - 1)"
                    >
                        <Minus :size="12" />
                    </Button>
                    <span class="w-6 text-center font-mono tabular-nums">{{ mulligans }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        class="size-7"
                        :disabled="mulligans === 6"
                        @click="setMulligans(mulligans + 1)"
                    >
                        <Plus :size="12" />
                    </Button>
                </div>
                <span class="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
                    {{ currentHand.length }} / {{ HAND_SIZE }}
                    <template v-if="editingFinal && mulligans > 0">, {{ bottomedCount }} / {{ mulligans }} bottomed</template>
                </span>
            </div>

            <div v-if="mulligans > 0" class="flex flex-wrap gap-1">
                <button
                    v-for="s in stages"
                    :key="s.index"
                    type="button"
                    class="rounded-md border px-2 py-1 font-mono text-[11px] transition"
                    :class="s.index === stage ? 'border-foreground/40 bg-muted' : 'hover:bg-muted/60'"
                    @click="stage = s.index"
                >
                    {{ s.label }}
                    <span class="text-muted-foreground">{{ s.count }}/{{ HAND_SIZE }}</span>
                </button>
            </div>

            <div class="flex min-h-24 flex-wrap gap-1.5 rounded-md border border-dashed p-2">
                <div v-for="(card, i) in currentCards" :key="`hand_${stage}_${i}`" class="group relative flex flex-col items-center gap-1">
                    <button
                        type="button"
                        class="aspect-[63/88] w-16 overflow-hidden rounded-md transition"
                        :class="isBottomed(i) ? 'opacity-60' : ''"
                        :title="editingFinal && mulligans > 0 ? (isBottomed(i) ? 'Unmark bottomed' : 'Mark bottomed') : 'Remove'"
                        @click="editingFinal && mulligans > 0 ? toggleBottomed(i) : removeAt(i)"
                    >
                        <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                        <span
                            v-else
                            class="flex h-full w-full items-center justify-center bg-muted p-1 text-center text-[10px] leading-tight text-muted-foreground"
                        >
                            {{ card.name }}
                        </span>
                    </button>
                    <CardTilePill v-if="isBottomed(i)" tone="bottomed">Bottom</CardTilePill>
                    <button
                        v-if="editingFinal && mulligans > 0"
                        type="button"
                        class="absolute -top-1 -right-1 hidden size-4 items-center justify-center rounded-full bg-background text-muted-foreground shadow ring-1 ring-border group-hover:flex hover:text-destructive"
                        :title="`Remove ${card.name}`"
                        @click.stop="removeAt(i)"
                    >
                        <X :size="10" />
                    </button>
                </div>
                <p v-if="currentCards.length === 0" class="w-full self-center text-center text-xs text-muted-foreground italic">
                    {{
                        editingFinal ? 'Click cards below to build the hand.' : 'Click cards below, or leave empty if you do not remember this hand.'
                    }}
                </p>
            </div>

            <div class="flex items-center justify-between text-xs text-muted-foreground">
                <span v-if="editingFinal && mulligans > 0">Click a card in the hand to mark it bottomed.</span>
                <span v-else-if="editingFinal">Click a card in the hand to remove it.</span>
                <span v-else>Hand you put back, then reshuffled.</span>
                <Button v-if="currentHand.length" type="button" variant="ghost" size="sm" class="h-6 px-1.5 text-[11px]" @click="clearCurrent">
                    Empty this hand
                </Button>
            </div>

            <div class="grid grid-cols-2 gap-1 overflow-y-auto sm:grid-cols-3">
                <button
                    v-for="card in mains"
                    :key="card.mtgoId"
                    type="button"
                    class="flex items-center justify-between gap-2 rounded-md border px-2 py-1 text-left text-xs transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="currentFull || remaining(card) <= 0"
                    @click="add(card)"
                >
                    <span class="truncate">{{ card.name }}</span>
                    <span class="shrink-0 font-mono text-[10px] text-muted-foreground tabular-nums">{{ remaining(card) }}/{{ card.quantity }}</span>
                </button>
            </div>

            <p v-if="firstError" class="text-xs text-destructive">{{ firstError }}</p>
            <p v-else-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>

            <DialogFooter class="gap-2">
                <Button
                    v-if="game.keptHand.length"
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="submit({ mulligan_count: 0, kept_hand: [], bottomed: [], mulliganed_hands: [] })"
                >
                    Clear hand
                </Button>
                <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                <Button type="button" :disabled="!canSave || form.processing" @click="submit()">Save hand</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
