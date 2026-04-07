<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import GameLogPanel from '@/components/matches/GameLogPanel.vue';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SwordsIcon, ChevronRight, Play, ScrollText } from 'lucide-vue-next';
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

const handOpen = ref(true);
const mulligansOpen = ref(false);
const sideboardOpen = ref(false);
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <!-- Game header -->
        <CardHeader class="flex flex-row flex-wrap items-center gap-2 bg-muted px-3 py-2">
            <div class="flex items-center gap-1.5">
                <span class="text-sm font-semibold">Game {{ game.number }}</span>
                <ResultBadge :won="game.won" />
            </div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span class="flex items-center gap-1">
                    <SwordsIcon :size="13" />
                    {{ game.onThePlay ? 'On the play' : 'On the draw' }}
                </span>
                <span v-if="game.duration">{{ game.duration }}</span>
                <span v-if="game.turns !== null">{{ game.turns }} turns</span>
            </div>
            <div class="ml-auto flex items-center gap-2 text-xs text-muted-foreground">
                <span v-if="game.localMulligans > 0">You mulliganed {{ game.localMulligans }}x</span>
                <span v-if="game.opponentMulligans > 0">{{ opponentName }} mulliganed {{ game.opponentMulligans }}x</span>
                <Dialog v-if="gameLog.length">
                    <DialogTrigger as-child>
                        <Button variant="ghost" size="sm" class="h-6 px-2 text-xs">
                            <ScrollText :size="11" />
                            Game Log
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[80vh] max-w-lg p-0">
                        <DialogHeader class="px-4 pt-4">
                            <DialogTitle>Game {{ game.number }} Log</DialogTitle>
                        </DialogHeader>
                        <div class="h-[60vh]">
                            <GameLogPanel :entries="gameLog" />
                        </div>
                    </DialogContent>
                </Dialog>
                <Button v-if="!imported" variant="ghost" size="sm" class="h-6 px-2 text-xs" @click="router.post(OpenReplayController.url({ id: game.id }))">
                    <Play :size="11" />
                    Replay
                </Button>
            </div>
        </CardHeader>

        <CardContent v-if="!imported" class="flex flex-col gap-1.5 px-3 py-2">
            <!-- Kept hand (open by default) -->
            <Collapsible v-model:open="handOpen">
                <CollapsibleTrigger class="flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase transition-colors hover:bg-muted">
                    <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': handOpen }" />
                    {{ game.localMulligans > 0 ? `Kept Hand (mulligan to ${7 - game.localMulligans})` : 'Opening Hand' }}
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div class="grid grid-cols-12 gap-0.5 px-2 pt-1.5">
                        <div v-for="(card, i) in game.keptHand" :key="`kept_${i}`" class="relative shrink-0">
                            <div
                                class="overflow-hidden rounded-[7px] border shadow-sm"
                                :class="card.bottomed ? 'border-destructive' : 'border-transparent'"
                            >
                                <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                    <span class="text-xs leading-tight text-muted-foreground">{{ card.name }}</span>
                                </div>
                            </div>
                            <div
                                v-if="card.bottomed"
                                class="absolute right-0 bottom-0 left-0 rounded-b-lg bg-destructive/85 py-0.5 text-center text-xs font-medium text-destructive-foreground"
                            >
                                Bottomed
                            </div>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <!-- Mulliganed hands (collapsed by default, only if mulligans happened) -->
            <Collapsible v-if="game.mulliganedHands.length" v-model:open="mulligansOpen">
                <CollapsibleTrigger class="flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase transition-colors hover:bg-muted">
                    <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': mulligansOpen }" />
                    Mulliganed Hands ({{ game.mulliganedHands.length }})
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div v-for="(hand, hi) in game.mulliganedHands" :key="`mull_${hi}`" class="px-2 pt-1.5">
                        <p v-if="game.mulliganedHands.length > 1" class="mb-1.5 text-xs text-muted-foreground">Hand {{ hi + 1 }}</p>
                        <div class="grid grid-cols-12 gap-0.5">
                            <div v-for="(card, ci) in hand" :key="`mull_${hi}_${ci}`" class="shrink-0 overflow-hidden rounded-[7px] border border-transparent">
                                <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                    <span class="text-xs leading-tight text-muted-foreground">{{ card.name }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <!-- Sideboard changes (collapsed by default, only games 2+) -->
            <Collapsible v-if="game.number > 1" v-model:open="sideboardOpen">
                <CollapsibleTrigger class="flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase transition-colors hover:bg-muted">
                    <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': sideboardOpen }" />
                    Sideboard Changes
                    <span v-if="game.sideboardChanges.length" class="normal-case">({{ game.sideboardChanges.length }})</span>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div v-if="game.sideboardChanges.length" class="grid grid-cols-12 gap-0.5 px-2 pt-1.5">
                        <div
                            v-for="change in game.sideboardChanges"
                            :key="`${change.type}_${change.name}`"
                            class="relative overflow-hidden rounded-[7px] border"
                            :class="change.type === 'in' ? 'border-success' : 'border-destructive'"
                        >
                            <img v-if="change.image" :src="change.image" :alt="change.name" class="h-full w-full object-cover" />
                            <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                <span class="text-xs leading-tight text-muted-foreground">{{ change.name }}</span>
                            </div>
                            <div
                                class="absolute right-0 bottom-0 left-0 py-0.5 text-center text-xs font-bold"
                                :class="change.type === 'in' ? 'bg-success/85 text-success-foreground' : 'bg-destructive/85 text-destructive-foreground'"
                            >
                                {{ change.type === 'in' ? '+' : '−' }}{{ change.quantity }}
                            </div>
                        </div>
                    </div>
                    <p v-else class="px-2 pt-1.5 text-xs text-muted-foreground">No changes</p>
                </CollapsibleContent>
            </Collapsible>
        </CardContent>
    </Card>
</template>
