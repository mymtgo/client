<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import GameLogPanel from '@/components/matches/GameLogPanel.vue';
import { Clock, Coins, Hand, Layers, Play, ScrollText, Undo2 } from 'lucide-vue-next';
import OpenReplayController from '@/actions/App/Http/Controllers/Games/OpenReplayController';

const props = defineProps<{
    game: {
        id: number;
        number: number;
        won: boolean;
        onThePlay: boolean;
        duration: string | null;
        turns: number | null;
        localMulligans: number;
        opponentMulligans: number;
        mulliganedHands: { name: string; image: string | null }[][];
        keptHand: { name: string; image: string | null; bottomed: boolean }[];
        sideboardChanges: { name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
    };
    gameLog: Array<{ timestamp: string; message: string }>;
    opponentName: string;
    imported?: boolean;
}>();

const sideboardCount = computed(() =>
    props.game.sideboardChanges.reduce((sum, c) => sum + c.quantity, 0),
);

const expectedKeptSize = computed(() => 7 - props.game.localMulligans);

const drawnIndex = computed(() => {
    if (props.game.onThePlay) return -1;
    if (props.game.keptHand.length > expectedKeptSize.value) {
        return props.game.keptHand.length - 1;
    }
    return -1;
});

const handGridClass = computed(() =>
    props.game.keptHand.length >= 8 ? 'grid-cols-8' : 'grid-cols-7',
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <!-- Meta strip -->
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b pb-4 font-mono text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1.5">
                <span class="text-muted-foreground/60">Result</span>
                <strong
                    class="font-medium"
                    :class="game.won ? 'text-success' : 'text-destructive'"
                >
                    {{ game.won ? 'Win' : 'Loss' }}
                </strong>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <Coins :size="12" class="text-muted-foreground/70" />
                <strong class="font-medium text-foreground">
                    {{ game.onThePlay ? 'On the play' : 'On the draw' }}
                </strong>
            </span>
            <span v-if="game.duration" class="inline-flex items-center gap-1.5">
                <Clock :size="12" class="text-muted-foreground/70" />
                <strong class="font-medium text-foreground">{{ game.duration }}</strong>
            </span>
            <span v-if="game.turns !== null" class="inline-flex items-center gap-1.5">
                <span class="text-muted-foreground/60">Turns</span>
                <strong class="font-medium text-foreground">{{ game.turns }}</strong>
            </span>
            <span v-if="game.localMulligans > 0" class="inline-flex items-center gap-1.5">
                <span class="text-muted-foreground/60">You mull</span>
                <strong class="font-medium text-foreground">×{{ game.localMulligans }}</strong>
            </span>
            <span v-if="game.opponentMulligans > 0" class="inline-flex items-center gap-1.5">
                <span class="text-muted-foreground/60">{{ opponentName }} mull</span>
                <strong class="font-medium text-foreground">×{{ game.opponentMulligans }}</strong>
            </span>

            <div class="ml-auto flex items-center gap-1">
                <Dialog v-if="gameLog.length">
                    <DialogTrigger as-child>
                        <Button variant="ghost" size="sm" class="h-7 px-2 text-xs">
                            <ScrollText :size="12" />
                            Game log
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[80vh] max-w-lg p-0">
                        <DialogHeader class="px-4 pt-4">
                            <DialogTitle>Game {{ game.number }} log</DialogTitle>
                        </DialogHeader>
                        <div class="h-[60vh]">
                            <GameLogPanel :entries="gameLog" />
                        </div>
                    </DialogContent>
                </Dialog>
                <Button
                    v-if="!imported"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    @click="router.post(OpenReplayController.url({ id: game.id }))"
                >
                    <Play :size="12" />
                    Replay
                </Button>
            </div>
        </div>

        <template v-if="!imported">
            <!-- Opening hand -->
            <section class="flex flex-col gap-3">
                <header class="flex items-baseline gap-3">
                    <Hand :size="13" class="text-muted-foreground" />
                    <h3 class="text-[11px] font-semibold tracking-widest uppercase">
                        {{ game.localMulligans > 0 ? `Kept hand (mulligan to ${7 - game.localMulligans})` : 'Opening hand' }}
                    </h3>
                    <span class="font-mono text-[11px] text-muted-foreground">({{ game.keptHand.length }})</span>
                    <span class="ml-2 h-px flex-1 bg-border" />
                </header>

                <div class="grid gap-1.5" :class="handGridClass">
                    <div v-for="(card, i) in game.keptHand" :key="`kept_${i}`" class="relative shrink-0">
                        <div
                            class="aspect-[63/88] overflow-hidden rounded-md border shadow-sm"
                            :class="[
                                card.bottomed
                                    ? 'border-destructive'
                                    : i === drawnIndex
                                      ? 'border-sky-400/70 ring-1 ring-sky-400/40'
                                      : 'border-transparent',
                            ]"
                        >
                            <img
                                v-if="card.image"
                                :src="card.image"
                                :alt="card.name"
                                class="h-full w-full object-cover"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                <span class="text-xs leading-tight text-muted-foreground">{{ card.name }}</span>
                            </div>
                        </div>
                        <div
                            v-if="card.bottomed"
                            class="absolute right-0 bottom-0 left-0 rounded-b-md bg-destructive/85 py-0.5 text-center text-[10px] font-medium text-destructive-foreground"
                        >
                            Bottomed
                        </div>
                        <div
                            v-else-if="i === drawnIndex"
                            class="absolute right-0 bottom-0 left-0 rounded-b-md bg-sky-500/85 py-0.5 text-center font-mono text-[10px] font-semibold tracking-widest text-white uppercase"
                        >
                            T1 Draw
                        </div>
                    </div>
                </div>
            </section>

            <!-- Mulliganed hands -->
            <section v-if="game.mulliganedHands.length" class="flex flex-col gap-3">
                <header class="flex items-baseline gap-3">
                    <Undo2 :size="13" class="text-muted-foreground" />
                    <h3 class="text-[11px] font-semibold tracking-widest uppercase">
                        Mulliganed hands
                    </h3>
                    <span class="font-mono text-[11px] text-muted-foreground">({{ game.mulliganedHands.length }})</span>
                    <span class="ml-2 h-px flex-1 bg-border" />
                </header>

                <div v-for="(hand, hi) in game.mulliganedHands" :key="`mull_${hi}`" class="flex flex-col gap-1.5">
                    <p v-if="game.mulliganedHands.length > 1" class="font-mono text-[11px] text-muted-foreground">
                        Hand {{ hi + 1 }}
                    </p>
                    <div class="grid grid-cols-7 gap-1.5">
                        <div
                            v-for="(card, ci) in hand"
                            :key="`mull_${hi}_${ci}`"
                            class="aspect-[63/88] shrink-0 overflow-hidden rounded-md border border-transparent"
                        >
                            <img
                                v-if="card.image"
                                :src="card.image"
                                :alt="card.name"
                                class="h-full w-full object-cover"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                <span class="text-xs leading-tight text-muted-foreground">{{ card.name }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sideboard changes -->
            <section v-if="game.number > 1" class="flex flex-col gap-3">
                <header class="flex items-baseline gap-3">
                    <Layers :size="13" class="text-muted-foreground" />
                    <h3 class="text-[11px] font-semibold tracking-widest uppercase">
                        Sideboard changes
                    </h3>
                    <span v-if="sideboardCount" class="font-mono text-[11px] text-muted-foreground">({{ sideboardCount }})</span>
                    <span class="ml-2 h-px flex-1 bg-border" />
                </header>

                <div v-if="game.sideboardChanges.length" class="grid grid-cols-10 gap-1.5">
                    <div
                        v-for="change in game.sideboardChanges"
                        :key="`${change.type}_${change.name}`"
                        class="relative aspect-[63/88] overflow-hidden rounded-md border"
                        :class="change.type === 'in' ? 'border-success ring-1 ring-success/30' : 'border-destructive ring-1 ring-destructive/30'"
                    >
                        <img
                            v-if="change.image"
                            :src="change.image"
                            :alt="change.name"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                            <span class="text-xs leading-tight text-muted-foreground">{{ change.name }}</span>
                        </div>
                        <div
                            class="absolute right-0 bottom-0 left-0 py-0.5 text-center font-mono text-[10px] font-bold"
                            :class="change.type === 'in' ? 'bg-success/85 text-success-foreground' : 'bg-destructive/85 text-destructive-foreground'"
                        >
                            {{ change.type === 'in' ? '+' : '−' }}{{ change.quantity }}
                        </div>
                    </div>
                </div>
                <p
                    v-else
                    class="rounded-md border border-dashed px-4 py-5 text-center text-xs text-muted-foreground italic"
                >
                    Pre-sideboard game — no changes made yet.
                </p>
            </section>
        </template>
    </div>
</template>
