<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { SwordsIcon } from 'lucide-vue-next';

defineProps<{
    game: {
        id: number;
        number: number;
        result: 'W' | 'L';
        onThePlay: boolean;
        duration: string;
        localMulligans: number;
        opponentMulligans: number;
        mulliganedHands: { name: string; image: string | null }[][];
        keptHand: { name: string; image: string | null; bottomed: boolean }[];
        sideboardChanges: { name: string; quantity: number; type: 'in' | 'out' }[];
        opponentCardsSeen: { name: string; image: string | null }[];
    };
    opponentName: string;
}>();
</script>

<template>
    <Card class="overflow-hidden">
        <!-- Game header -->
        <CardHeader class="bg-muted flex flex-row flex-wrap items-center gap-3 px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="font-semibold">Game {{ game.number }}</span>
                <Badge v-if="game.result === 'W'" variant="default">Win</Badge>
                <Badge v-else variant="destructive">Loss</Badge>
            </div>
            <div class="text-muted-foreground flex items-center gap-3 text-sm">
                <span class="flex items-center gap-1">
                    <SwordsIcon :size="13" />
                    {{ game.onThePlay ? 'On the play' : 'On the draw' }}
                </span>
                <span>{{ game.duration }}</span>
                <span v-if="game.localMulligans > 0">You mulliganed {{ game.localMulligans }}×</span>
                <span v-if="game.opponentMulligans > 0">{{ opponentName }} mulliganed {{ game.opponentMulligans }}×</span>
            </div>
        </CardHeader>

        <CardContent class="flex gap-6 px-4 py-4">
            <!-- Left: hands + sideboard + replay -->
            <div class="flex min-w-0 flex-1 flex-col gap-4">

                <!-- Kept hand -->
                <div>
                    <p class="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                        {{ game.localMulligans > 0 ? `Kept Hand (mulligan to ${7 - game.localMulligans})` : 'Opening Hand' }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <div v-for="(card, i) in game.keptHand" :key="`kept_${i}`" class="relative shrink-0">
                            <div
                                class="h-28 w-20 overflow-hidden rounded-lg border-2 shadow-sm"
                                :class="card.bottomed ? 'border-destructive' : 'border-transparent'"
                            >
                                <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                                <div v-else class="bg-muted flex h-full w-full items-center justify-center p-1.5 text-center">
                                    <span class="text-muted-foreground text-xs leading-tight">{{ card.name }}</span>
                                </div>
                            </div>
                            <div
                                v-if="card.bottomed"
                                class="bg-destructive/85 text-destructive-foreground absolute bottom-0 left-0 right-0 rounded-b-lg py-0.5 text-center text-xs font-medium"
                            >
                                Bottomed
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mulliganed hands (shuffled back) -->
                <div v-for="(hand, hi) in game.mulliganedHands" :key="`mull_${hi}`">
                    <p class="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                        Hand {{ hi + 1 }} — Shuffled Back
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="(card, ci) in hand"
                            :key="`mull_${hi}_${ci}`"
                            class="h-28 w-20 shrink-0 overflow-hidden rounded-lg border-2 border-transparent opacity-50 shadow-sm"
                        >
                            <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                            <div v-else class="bg-muted flex h-full w-full items-center justify-center p-1.5 text-center">
                                <span class="text-muted-foreground text-xs leading-tight">{{ card.name }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sideboard changes -->
                <div>
                    <p class="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">Sideboard</p>
                    <div v-if="game.sideboardChanges.length" class="space-y-1">
                        <div
                            v-for="change in game.sideboardChanges"
                            :key="`${change.type}_${change.name}`"
                            class="flex items-center gap-1.5 text-sm"
                        >
                            <span class="w-6 font-mono font-semibold" :class="change.type === 'out' ? 'text-destructive' : ''">
                                {{ change.type === 'in' ? '+' : '−' }}{{ change.quantity }}
                            </span>
                            <span>{{ change.name }}</span>
                        </div>
                    </div>
                    <p v-else class="text-muted-foreground text-sm">No changes</p>
                </div>

                <div class="flex justify-end">
                    <Button variant="outline" size="sm">View Replay</Button>
                </div>
            </div>

            <!-- Right: opponent cards seen -->
            <div class="w-48 shrink-0">
                <p class="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">Opponent Cards Seen</p>
                <div v-if="game.opponentCardsSeen.length" class="grid grid-cols-3 gap-1.5">
                    <HoverCard v-for="(card, i) in game.opponentCardsSeen" :key="`opp_${i}`" :open-delay="100">
                        <HoverCardTrigger>
                            <div class="h-20 w-full cursor-pointer overflow-hidden rounded-md border border-border shadow-sm transition-opacity hover:opacity-90">
                                <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                                <div v-else class="bg-muted flex h-full w-full items-center justify-center p-1">
                                    <span class="text-muted-foreground text-center text-xs leading-tight">{{ card.name }}</span>
                                </div>
                            </div>
                        </HoverCardTrigger>
                        <HoverCardContent side="left" class="w-auto p-0">
                            <img v-if="card.image" :src="card.image" :alt="card.name" class="w-48 rounded-xl" />
                            <div v-else class="px-3 py-2 text-sm font-medium">{{ card.name }}</div>
                        </HoverCardContent>
                    </HoverCard>
                </div>
                <p v-else class="text-muted-foreground text-sm">None recorded</p>
            </div>
        </CardContent>
    </Card>
</template>
