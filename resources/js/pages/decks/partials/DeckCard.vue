<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import WinRateBar from '@/components/WinRateBar.vue';
import { manaWash } from '@/lib/mana';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    deck: App.Data.Front.DeckData;
}>();

/**
 * Decks without cover art still fill the art band, tinted by their colour
 * identity. Collapsing those tiles to a text block instead left the grid full
 * of holes wherever a synced deck had no art yet.
 */
const fallbackArtStyle = computed(() => ({ backgroundImage: manaWash(props.deck.colorIdentity) }));
</script>

<template>
    <Link :href="ShowController({ deck: deck.id }).url" prefetch="hover" cache-for="10s" class="group block h-full">
        <Card
            class="relative flex h-full cursor-pointer flex-col gap-0 overflow-hidden py-0 transition-colors group-hover:border-white/10 group-hover:bg-black/25"
        >
            <img
                v-if="deck.coverArt"
                :src="deck.coverArt"
                :alt="deck.name"
                class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top transition-transform duration-500 ease-out group-hover:scale-[1.04]"
                :class="deck.deletedAt ? 'grayscale' : ''"
            />
            <div v-else class="pointer-events-none absolute inset-0" :class="deck.deletedAt ? 'grayscale' : ''" :style="fallbackArtStyle" />

            <!--
                Explicit gradient scrim rather than a mask utility: masks do not
                render reliably in the Electron webview. Dark enough at the foot
                of the card to carry the stats text.
            -->
            <div class="pointer-events-none absolute inset-0 bg-linear-to-t from-black via-black/75 to-black/20" />

            <!--
                Pinned to the card rather than sitting inline beside the name so
                a long name gets the full tile width.
            -->
            <ManaSymbols
                v-if="deck.colorIdentity"
                :symbols="deck.colorIdentity"
                class="absolute top-3 right-3 z-10 rounded-full border border-white/10 bg-black/60 px-2 py-1 shadow shadow-black/50 backdrop-blur-sm"
            />

            <!-- Spacer that gives the art band room to read as art. -->
            <div class="relative h-44 shrink-0" />

            <div class="relative flex flex-1 flex-col justify-end gap-3 p-4 [text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]">
                <span class="line-clamp-2 text-base leading-tight font-semibold">{{ deck.name }}</span>

                <div class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                    <Badge variant="outline" class="shrink-0 py-0 text-xs">{{ deck.format }}</Badge>
                    <Badge v-if="deck.deletedAt" variant="destructive" class="shrink-0 py-0 text-xs">Deleted</Badge>
                    <span v-else class="truncate">{{ deck.lastPlayedAtHuman ?? 'never played' }}</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <WinRateBar :winrate="deck.winrate" solid />
                    <div class="flex items-baseline justify-between gap-2 text-xs tabular-nums">
                        <span class="text-muted-foreground">{{ deck.matchesCount }} matches</span>
                        <span class="text-muted-foreground">
                            <span class="text-foreground">{{ deck.matchesWon }}W</span>
                            <span class="mx-0.5">-</span>
                            <span class="text-destructive">{{ deck.matchesLost }}L</span>
                            <template v-if="deck.matchesDrawn > 0">
                                <span class="mx-0.5">-</span>
                                <span>{{ deck.matchesDrawn }}D</span>
                            </template>
                        </span>
                    </div>
                </div>
            </div>
        </Card>
    </Link>
</template>
