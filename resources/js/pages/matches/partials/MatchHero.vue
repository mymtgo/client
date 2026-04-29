<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Clock, PencilIcon } from 'lucide-vue-next';

const props = defineProps<{
    match: App.Data.Front.MatchData;
    deck: App.Data.Front.DeckData;
    opponentArchetype: App.Data.Front.MatchArchetypeData | null;
    onEditArchetype?: () => void;
}>();

const isWin = computed(() => props.match.gamesWon > props.match.gamesLost);
const totalGames = computed(() => props.match.gamesWon + props.match.gamesLost);
</script>

<template>
    <header class="flex flex-col gap-2.5 border-b pb-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <!-- Score -->
            <span
                class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold tracking-widest uppercase"
                :class="isWin
                    ? 'border-success/40 bg-success/10 text-success'
                    : 'border-destructive/40 bg-destructive/10 text-destructive'"
            >
                <span class="size-1.5 rounded-full" :class="isWin ? 'bg-success' : 'bg-destructive'" />
                {{ isWin ? 'Win' : 'Loss' }}
            </span>
            <span class="font-mono text-base font-semibold tabular-nums">
                {{ match.gamesWon }}–{{ match.gamesLost }}
            </span>

            <span class="h-4 w-px bg-border" />

            <!-- Matchup -->
            <h1 class="flex flex-wrap items-center gap-1.5 text-sm font-semibold">
                <ManaSymbols
                    v-if="deck.colorIdentity"
                    :symbols="deck.colorIdentity"
                    class="[&_svg]:size-3"
                />
                {{ deck.archetype?.name ?? deck.name }}
                <span class="text-muted-foreground/60 font-normal">vs</span>
                <ManaSymbols
                    v-if="opponentArchetype?.archetype?.colorIdentity"
                    :symbols="opponentArchetype.archetype.colorIdentity"
                    class="[&_svg]:size-3"
                />
                <span :class="{ 'italic text-muted-foreground': !opponentArchetype?.archetype }">
                    {{ opponentArchetype?.archetype?.name ?? 'Unknown archetype' }}
                </span>
                <Button
                    v-if="onEditArchetype"
                    variant="ghost"
                    size="icon"
                    class="size-5 text-muted-foreground"
                    @click="onEditArchetype"
                >
                    <PencilIcon :size="11" />
                </Button>
                <span class="text-muted-foreground/60 font-normal">·</span>
                <span class="text-muted-foreground font-normal">{{ match.opponentName ?? 'Unknown' }}</span>
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[11px] text-muted-foreground">
            <span>{{ match.format }}</span>
            <span class="text-muted-foreground/40">·</span>
            <span>{{ match.startedAtFormatted }}</span>
            <span class="text-muted-foreground/40">·</span>
            <span>{{ match.since }}</span>
            <template v-if="match.matchTime">
                <span class="text-muted-foreground/40">·</span>
                <span class="inline-flex items-center gap-1">
                    <Clock :size="11" />
                    {{ match.matchTime }}
                </span>
            </template>
            <span class="text-muted-foreground/40">·</span>
            <span>{{ totalGames }} {{ totalGames === 1 ? 'game' : 'games' }}</span>
            <template v-if="match.leagueName">
                <span class="text-muted-foreground/40">·</span>
                <span>{{ match.leagueName }}</span>
            </template>
        </div>
    </header>
</template>
