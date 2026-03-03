<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { filter, find } from 'lodash';
import { BookOpen, Hand, Heart, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { GameTimelineEvent } from './GameReplay.vue';

const props = defineProps<{
    event: GameTimelineEvent | null;
}>();

const player = computed(() => find(props.event?.content.Players, (p: any) => p.IsLocal));
const opponent = computed(() => find(props.event?.content.Players, (p: any) => !p.IsLocal));

const cards = computed(() => props.event?.content.Cards || []);

const playerBattlefield = computed(() => filter(cards.value, (c) => c.Zone === 'Battlefield' && c.Controller == player.value?.Id));
const opponentBattlefield = computed(() => filter(cards.value, (c) => c.Zone === 'Battlefield' && c.Controller != player.value?.Id));
const stack = computed(() => filter(cards.value, (c) => c.Zone === 'Stack'));

const isLand = (c: any) => (c.type ?? '').includes('Land');
const isCreature = (c: any) => (c.type ?? '').includes('Creature');
const isPlaneswalker = (c: any) => (c.type ?? '').includes('Planeswalker');
const isOther = (c: any) => !isLand(c) && !isCreature(c) && !isPlaneswalker(c);

function splitBattlefield(bf: any[]) {
    return {
        creatures: filter(bf, (c) => isCreature(c) || isPlaneswalker(c)),
        lands: filter(bf, isLand),
        other: filter(bf, (c) => isOther(c) && !isPlaneswalker(c)),
    };
}

const playerZones = computed(() => splitBattlefield(playerBattlefield.value));
const opponentZones = computed(() => splitBattlefield(opponentBattlefield.value));
const playerHand = computed(() => filter(cards.value, (c) => c.Zone === 'Hand' && c.Owner == player.value?.Id));
const playerGraveyard = computed(() => filter(cards.value, (c) => c.Zone === 'Graveyard' && c.Owner == player.value?.Id));
const opponentGraveyard = computed(() => filter(cards.value, (c) => c.Zone === 'Graveyard' && c.Owner != player.value?.Id));
const playerExile = computed(() => filter(cards.value, (c) => c.Zone === 'Exile' && c.Owner == player.value?.Id));
const opponentExile = computed(() => filter(cards.value, (c) => c.Zone === 'Exile' && c.Owner != player.value?.Id));

type ZonePanel = 'opp-graveyard' | 'opp-exile' | 'player-graveyard' | 'player-exile';
const openPanels = ref<Set<ZonePanel>>(new Set());

function togglePanel(panel: ZonePanel) {
    if (openPanels.value.has(panel)) {
        openPanels.value.delete(panel);
    } else {
        openPanels.value.add(panel);
    }
}

const visiblePanels = computed(() => {
    const panels: { key: ZonePanel; label: string; cards: any[] }[] = [];
    if (openPanels.value.has('opp-graveyard')) {
        panels.push({ key: 'opp-graveyard', label: `${opponent.value?.Name ?? 'Opponent'}'s Graveyard`, cards: opponentGraveyard.value });
    }
    if (openPanels.value.has('opp-exile')) {
        panels.push({ key: 'opp-exile', label: `${opponent.value?.Name ?? 'Opponent'}'s Exile`, cards: opponentExile.value });
    }
    if (openPanels.value.has('player-graveyard')) {
        panels.push({ key: 'player-graveyard', label: `${player.value?.Name ?? 'Your'} Graveyard`, cards: playerGraveyard.value });
    }
    if (openPanels.value.has('player-exile')) {
        panels.push({ key: 'player-exile', label: `${player.value?.Name ?? 'Your'} Exile`, cards: playerExile.value });
    }
    return panels;
});

const hasOpenPanels = computed(() => openPanels.value.size > 0);
</script>

<template>
    <div v-if="!event" class="rounded-lg border border-dashed p-8 text-center text-muted-foreground">No event selected.</div>

    <div v-else class="flex max-h-[80vh] overflow-hidden rounded-lg border bg-card">
        <!-- Left sidebar: player info + stack -->
        <div class="flex w-56 shrink-0 flex-col border-r">
            <!-- Opponent info -->
            <div class="flex flex-col gap-1 border-b bg-muted/50 px-3 py-2">
                <span class="text-sm font-semibold">{{ opponent?.Name ?? 'Opponent' }}</span>
                <div class="flex items-center gap-3 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1"><Heart :size="12" class="text-destructive" /> {{ opponent?.Life ?? 0 }}</span>
                    <span class="flex items-center gap-1"><Hand :size="12" /> {{ opponent?.HandCount ?? 0 }}</span>
                    <span class="flex items-center gap-1"><BookOpen :size="12" /> {{ opponent?.LibraryCount ?? 0 }}</span>
                </div>
            </div>

            <!-- Opponent graveyard / exile toggles -->
            <div class="flex gap-1 border-b px-3 py-1.5">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs"
                    :class="{ 'bg-accent': openPanels.has('opp-graveyard') }"
                    @click="togglePanel('opp-graveyard')"
                >
                    Graveyard ({{ opponentGraveyard.length }})
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs"
                    :class="{ 'bg-accent': openPanels.has('opp-exile') }"
                    @click="togglePanel('opp-exile')"
                >
                    Exile ({{ opponentExile.length }})
                </Button>
            </div>

            <!-- Stack -->
            <div class="flex flex-1 flex-col gap-1 border-b px-3 py-2">
                <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Stack</span>
                <div v-if="stack.length" class="flex flex-col gap-0.5">
                    <HoverCard v-for="card in stack" :key="card.Id">
                        <HoverCardTrigger as-child>
                            <button class="truncate rounded px-1.5 py-0.5 text-left text-xs transition-colors hover:bg-muted">
                                {{ card.name ?? 'Unknown' }}
                            </button>
                        </HoverCardTrigger>
                        <HoverCardContent side="right" class="w-auto p-1" v-if="card.image">
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-48 rounded" />
                        </HoverCardContent>
                    </HoverCard>
                </div>
                <span v-else class="text-xs text-muted-foreground italic">Empty</span>
            </div>

            <!-- Player info -->
            <div class="flex flex-col gap-1 border-b bg-muted/50 px-3 py-2">
                <span class="text-sm font-semibold">{{ player?.Name ?? 'You' }}</span>
                <div class="flex items-center gap-3 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1"><Heart :size="12" class="text-destructive" /> {{ player?.Life ?? 0 }}</span>
                    <span class="flex items-center gap-1"><Hand :size="12" /> {{ player?.HandCount ?? 0 }}</span>
                    <span class="flex items-center gap-1"><BookOpen :size="12" /> {{ player?.LibraryCount ?? 0 }}</span>
                </div>
            </div>

            <!-- Player graveyard / exile toggles -->
            <div class="flex gap-1 px-3 py-1.5">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs"
                    :class="{ 'bg-accent': openPanels.has('player-graveyard') }"
                    @click="togglePanel('player-graveyard')"
                >
                    Graveyard ({{ playerGraveyard.length }})
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs"
                    :class="{ 'bg-accent': openPanels.has('player-exile') }"
                    @click="togglePanel('player-exile')"
                >
                    Exile ({{ playerExile.length }})
                </Button>
            </div>
        </div>

        <!-- Zone panels (graveyard/exile) — shown to the right of sidebar when toggled -->
        <div v-if="hasOpenPanels" class="flex min-h-0 shrink-0 border-r">
            <div v-for="panel in visiblePanels" :key="panel.key" class="flex min-h-0 w-40 flex-col border-r last:border-r-0">
                <div class="flex items-center justify-between gap-1 border-b bg-muted/30 px-2 py-1.5">
                    <span class="truncate text-xs font-medium">{{ panel.label }}</span>
                    <button
                        class="rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        @click="togglePanel(panel.key)"
                    >
                        <X :size="12" />
                    </button>
                </div>
                <div class="scrollbar-none flex-1 overflow-y-auto p-1.5">
                    <div v-if="panel.cards.length" class="flex flex-col gap-1">
                        <HoverCard v-for="card in panel.cards" :key="card.Id">
                            <HoverCardTrigger as-child>
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-full cursor-pointer rounded" />
                            </HoverCardTrigger>
                            <HoverCardContent side="right" class="w-auto p-1" v-if="card.image">
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                            </HoverCardContent>
                        </HoverCard>
                    </div>
                    <div v-else class="flex h-full items-center justify-center py-4 text-xs text-muted-foreground">Empty</div>
                </div>
            </div>
        </div>

        <!-- Main battlefield area -->
        <div class="flex flex-1 flex-col">
            <!-- Opponent lands + non-creatures (side by side) -->
            <div class="grid min-h-20 grid-cols-2 border-b">
                <div class="border-r p-2">
                    <div v-if="opponentZones.lands.length" class="flex flex-wrap gap-1">
                        <HoverCard v-for="card in opponentZones.lands" :key="card.Id">
                            <HoverCardTrigger as-child>
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-20 cursor-pointer rounded-[7px]" />
                            </HoverCardTrigger>
                            <HoverCardContent side="top" class="w-auto p-1" v-if="card.image">
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                            </HoverCardContent>
                        </HoverCard>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-xs text-muted-foreground">No lands</div>
                </div>
                <div class="p-2">
                    <div v-if="opponentZones.other.length" class="flex flex-wrap gap-1">
                        <HoverCard v-for="card in opponentZones.other" :key="card.Id">
                            {{ card }}

                            <HoverCardTrigger as-child>
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-20 cursor-pointer rounded-[7px]" />
                            </HoverCardTrigger>
                            <HoverCardContent side="top" class="w-auto p-1" v-if="card.image">
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                            </HoverCardContent>
                        </HoverCard>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-xs text-muted-foreground">No non-creatures</div>
                </div>
            </div>

            <!-- Opponent creatures -->
            <div class="min-h-24 border-b p-2">
                <div v-if="opponentZones.creatures.length" class="flex flex-wrap gap-1">
                    <HoverCard v-for="card in opponentZones.creatures" :key="card.Id">
                        <HoverCardTrigger as-child>
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-24 cursor-pointer rounded-[7px]" />
                        </HoverCardTrigger>
                        <HoverCardContent side="bottom" class="w-auto p-1" v-if="card.image">
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                        </HoverCardContent>
                    </HoverCard>
                </div>
                <div v-else class="flex h-full min-h-16 items-center justify-center text-xs text-muted-foreground">No creatures</div>
            </div>

            <!-- Player creatures -->
            <div class="min-h-24 border-b p-2">
                <div v-if="playerZones.creatures.length" class="flex flex-wrap gap-1">
                    <HoverCard v-for="card in playerZones.creatures" :key="card.Id">
                        <HoverCardTrigger as-child>
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-24 cursor-pointer rounded-[7px]" />
                        </HoverCardTrigger>
                        <HoverCardContent side="top" class="w-auto p-1" v-if="card.image">
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                        </HoverCardContent>
                    </HoverCard>
                </div>
                <div v-else class="flex h-full min-h-16 items-center justify-center text-xs text-muted-foreground">No creatures</div>
            </div>

            <!-- Player lands + non-creatures (side by side) -->
            <div class="grid min-h-20 grid-cols-2 border-b">
                <div class="border-r p-2">
                    <div v-if="playerZones.lands.length" class="flex flex-wrap gap-1">
                        <HoverCard v-for="card in playerZones.lands" :key="card.Id">
                            <HoverCardTrigger as-child>
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-20 cursor-pointer rounded-[7px]" />
                            </HoverCardTrigger>
                            <HoverCardContent side="bottom" class="w-auto p-1" v-if="card.image">
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                            </HoverCardContent>
                        </HoverCard>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-xs text-muted-foreground">No lands</div>
                </div>
                <div class="p-2">
                    <div v-if="playerZones.other.length" class="flex flex-wrap gap-1">
                        <HoverCard v-for="card in playerZones.other" :key="card.Id">
                            <HoverCardTrigger as-child>
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-20 cursor-pointer rounded-[7px]" />
                            </HoverCardTrigger>
                            <HoverCardContent side="bottom" class="w-auto p-1" v-if="card.image">
                                <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                            </HoverCardContent>
                        </HoverCard>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-xs text-muted-foreground">No non-creatures</div>
                </div>
            </div>

            <!-- Player hand -->
            <div class="p-2">
                <div v-if="playerHand.length" class="flex flex-wrap gap-1">
                    <HoverCard v-for="card in playerHand" :key="card.Id">
                        <HoverCardTrigger as-child>
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-24 cursor-pointer rounded-[7px]" />
                        </HoverCardTrigger>
                        <HoverCardContent side="top" class="w-auto p-1" v-if="card.image">
                            <img :src="card.image" :alt="card.name ?? 'card'" class="w-56 rounded" />
                        </HoverCardContent>
                    </HoverCard>
                </div>
                <div v-else class="flex h-12 items-center justify-center text-xs text-muted-foreground">Empty hand</div>
            </div>
        </div>
    </div>
</template>
