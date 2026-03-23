<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { TrendingUp, TrendingDown, Minus, Trophy } from 'lucide-vue-next';

type ActiveLeague = {
    name: string;
    format: string;
    phantom: boolean;
    isActive: boolean;
    isTrophy: boolean;
    deckName: string | null;
    results: ('W' | 'L' | null)[];
    wins: number;
    losses: number;
    matchesRemaining: number;
};

type Streak = {
    current: string | null;
    bestWin: number;
    bestLoss: number;
};

type PlayDrawSplit = {
    otpWinrate: number;
    otdWinrate: number;
};

const props = defineProps<{
    streak: Streak;
    matchWinrate: number;
    matchWinrateDelta: number;
    gameWinrate: number;
    gameWinrateDelta: number;
    playDrawSplit: PlayDrawSplit;
    activeLeague: ActiveLeague | null;
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
}>();

const streakIsWin = computed(() => props.streak.current?.endsWith('W') ?? false);
const streakIsLoss = computed(() => props.streak.current?.endsWith('L') ?? false);
</script>

<template>
    <Card>
        <CardContent class="grid grid-cols-5 divide-x divide-border p-0">
            <!-- Cell 1: Current Streak -->
            <div class="flex flex-col items-center gap-1 px-4 py-3">
                <span
                    class="text-3xl font-bold tabular-nums"
                    :class="{
                        'text-success': streakIsWin,
                        'text-destructive': streakIsLoss,
                        'text-muted-foreground': !streak.current,
                    }"
                >
                    {{ streak.current ?? '—' }}
                </span>
                <span class="text-xs text-muted-foreground">Current Streak</span>
                <span v-if="streak.bestWin > 0 || streak.bestLoss > 0" class="text-xs text-muted-foreground/60 tabular-nums">
                    Best: {{ streak.bestWin }}W / {{ streak.bestLoss }}L
                </span>
            </div>

            <!-- Cell 2: Match Win Rate -->
            <div class="flex flex-col items-center gap-1 px-4 py-3">
                <div class="flex items-center gap-1">
                    <span
                        class="text-3xl font-bold tabular-nums"
                        :class="matchWinrate < 50 ? 'text-destructive' : ''"
                    >
                        {{ matchWinrate }}%
                    </span>
                    <TrendingUp v-if="matchWinrateDelta > 0" class="size-4 text-success" />
                    <TrendingDown v-else-if="matchWinrateDelta < 0" class="size-4 text-destructive" />
                    <Minus v-else class="size-4 text-muted-foreground" />
                </div>
                <span class="text-xs text-muted-foreground">Match Win Rate</span>
                <span class="text-xs tabular-nums text-muted-foreground/60">
                    {{ matchesWon }}W – {{ matchesLost }}L
                    <span
                        v-if="matchWinrateDelta !== 0"
                        :class="matchWinrateDelta > 0 ? 'text-success' : 'text-destructive'"
                    >
                        ({{ matchWinrateDelta > 0 ? '+' : '' }}{{ matchWinrateDelta }}%)
                    </span>
                </span>
            </div>

            <!-- Cell 3: Game Win Rate -->
            <div class="flex flex-col items-center gap-1 px-4 py-3">
                <div class="flex items-center gap-1">
                    <span
                        class="text-3xl font-bold tabular-nums"
                        :class="gameWinrate < 50 ? 'text-destructive' : ''"
                    >
                        {{ gameWinrate }}%
                    </span>
                    <TrendingUp v-if="gameWinrateDelta > 0" class="size-4 text-success" />
                    <TrendingDown v-else-if="gameWinrateDelta < 0" class="size-4 text-destructive" />
                    <Minus v-else class="size-4 text-muted-foreground" />
                </div>
                <span class="text-xs text-muted-foreground">Game Win Rate</span>
                <span class="text-xs tabular-nums text-muted-foreground/60">
                    {{ gamesWon }}W – {{ gamesLost }}L
                    <span
                        v-if="gameWinrateDelta !== 0"
                        :class="gameWinrateDelta > 0 ? 'text-success' : 'text-destructive'"
                    >
                        ({{ gameWinrateDelta > 0 ? '+' : '' }}{{ gameWinrateDelta }}%)
                    </span>
                </span>
            </div>

            <!-- Cell 4: Play/Draw Split -->
            <div class="flex flex-col items-center gap-1 px-4 py-3">
                <div class="flex items-center gap-3 tabular-nums">
                    <div class="flex flex-col items-center">
                        <span class="text-2xl font-bold">{{ playDrawSplit.otpWinrate }}%</span>
                        <span class="text-xs text-muted-foreground">On Play</span>
                    </div>
                    <span class="text-muted-foreground/40 text-lg">/</span>
                    <div class="flex flex-col items-center">
                        <span class="text-2xl font-bold">{{ playDrawSplit.otdWinrate }}%</span>
                        <span class="text-xs text-muted-foreground">On Draw</span>
                    </div>
                </div>
                <span class="text-xs text-muted-foreground">Play / Draw Split</span>
            </div>

            <!-- Cell 5: Active League -->
            <div class="flex flex-col items-center gap-1 px-4 py-3">
                <template v-if="activeLeague">
                    <span v-if="activeLeague.deckName" class="max-w-full truncate text-sm font-semibold" :title="activeLeague.deckName">
                        {{ activeLeague.deckName }}
                    </span>
                    <span class="text-[10px] text-muted-foreground/60">{{ activeLeague.format }}</span>
                    <div class="flex items-center gap-1.5">
                        <template v-for="(result, i) in activeLeague.results" :key="i">
                            <ResultBadge :won="result === 'W'" />
                        </template>
                        <Trophy v-if="activeLeague.isTrophy" class="size-3.5 text-yellow-400" />
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ activeLeague.isActive ? 'Active League' : 'Last League' }}
                    </span>
                    <span class="text-xs tabular-nums text-muted-foreground/60">
                        {{ activeLeague.wins }}W – {{ activeLeague.losses }}L
                        <span v-if="activeLeague.isActive">· {{ activeLeague.matchesRemaining }} left</span>
                    </span>
                </template>
                <template v-else>
                    <span class="text-2xl font-bold text-muted-foreground">—</span>
                    <span class="text-xs text-muted-foreground">No active league</span>
                </template>
            </div>
        </CardContent>
    </Card>
</template>
