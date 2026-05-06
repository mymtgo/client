<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import ManaSymbols from '@/components/ManaSymbols.vue';
import WinRateBar from '@/components/WinRateBar.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    deck: App.Data.Front.DeckData;
}>();
</script>

<template>
    <Link :href="ShowController({ deck: deck.id }).url" prefetch="hover" cache-for="10s" class="block">
        <Card class="relative cursor-pointer overflow-hidden transition-colors hover:bg-black/20">
            <img
                v-if="deck.coverArt"
                :src="deck.coverArt"
                :alt="deck.name"
                class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top opacity-50"
            />
            <CardContent class="relative flex flex-col gap-3" :class="deck.coverArt ? '[text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]' : ''">
                <div class="flex justify-between gap-1">
                    <div class="flex min-w-0 items-center gap-1.5">
                        <span class="truncate leading-tight font-semibold">{{ deck.name }}</span>
                        <ManaSymbols v-if="deck.colorIdentity" :symbols="deck.colorIdentity" class="shrink-0" />
                    </div>
                    <div class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        <Badge variant="outline" class="py-0 text-xs">{{ deck.format }}</Badge>
                        <template v-if="deck.deletedAt">
                            <Badge variant="destructive" class="py-0 text-xs">Deleted</Badge>
                        </template>
                        <template v-else>
                            <span>·</span>
                            <span>Last played {{ deck.lastPlayedAtHuman ?? 'never' }}</span>
                        </template>
                    </div>
                </div>

                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-1 flex-col gap-1">
                        <span class="text-xs text-muted-foreground">win rate</span>
                        <WinRateBar :winrate="deck.winrate" :solid="!!deck.coverArt" />
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium tabular-nums">{{ deck.matchesCount }} matches</div>
                        <div class="text-xs text-muted-foreground tabular-nums">
                            <span>{{ deck.matchesWon }}W</span>
                            <span class="mx-0.5">-</span>
                            <span class="text-destructive">{{ deck.matchesLost }}L</span>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </Link>
</template>
