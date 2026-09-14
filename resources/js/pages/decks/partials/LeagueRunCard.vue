<script setup lang="ts">
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import LeagueResultBadge from '@/components/leagues/LeagueResultBadge.vue';
import LeagueScreenshot from '@/components/leagues/LeagueScreenshot.vue';
import RunSummaryStats from '@/components/leagues/RunSummaryStats.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useScreenshot } from '@/composables/useScreenshot';
import type { LeagueGameResult, LeagueRun } from '@/types/leagues';
import { router } from '@inertiajs/vue3';
import { Calendar, Camera } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/** A run, plus the projection fields that only a run still being played carries. */
type LeagueRunWithOdds = LeagueRun & {
    winProbability?: number;
    oddsSource?: string;
    odds?: {
        roundsLeft: number;
        prizeWins: number | null;
        winsForPrize: number | null;
        chanceOfPrize: number | null;
        expectedTix: number | null;
    };
};

const props = defineProps<{
    run: LeagueRunWithOdds;
}>();

const live = computed(() => props.run.state === 'active');

const wins = computed(() => props.run.results.filter((r) => r === 'W').length);
const losses = computed(() => props.run.results.filter((r) => r === 'L').length);

function gameRecord(games: LeagueGameResult[]): string {
    const won = games.filter((g) => g.result === 'W').length;
    const lost = games.filter((g) => g.result === 'L').length;

    return `${won}–${lost}`;
}

/**
 * Where the projection came from. A deck that has barely played leagues is
 * predicted from its overall record instead, and saying so keeps the number
 * from reading as better informed than it is.
 */
const oddsNote = computed(() => {
    if (props.run.oddsSource === 'league') return `from a ${props.run.winProbability}% league win rate`;
    if (props.run.oddsSource === 'deck') return `from this deck's ${props.run.winProbability}% overall win rate`;

    return 'from an even chance each round, for want of a record';
});

const screenshotRef = ref<InstanceType<typeof LeagueScreenshot> | null>(null);
const showScreenshot = ref(false);
const { capture, capturing } = useScreenshot();

async function copyScreenshot() {
    showScreenshot.value = true;
    await nextTick();
    const el = screenshotRef.value?.$el as HTMLElement | undefined;
    if (el) {
        await capture(el);
    }
    showScreenshot.value = false;
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <div class="flex items-start gap-3 px-4 py-3">
            <LeagueResultBadge
                :classification="run.classification"
                :wins="wins"
                :losses="losses"
                :live-round="run.liveRound"
            />

            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ live ? 'League in progress' : 'Latest league' }}</span>
                    <div class="flex items-center gap-1">
                        <template v-for="(result, index) in run.results" :key="index">
                            <div v-if="result === null" class="size-2 rounded-full border border-muted-foreground/40" />
                            <ResultBadge v-else :won="result === 'W'" />
                        </template>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span v-if="run.startedAtHuman" class="inline-flex items-center gap-1">
                        <Calendar class="size-3" />
                        {{ run.startedAtHuman }}
                    </span>
                    <span v-if="live && run.odds">
                        {{ run.odds.roundsLeft }} {{ run.odds.roundsLeft === 1 ? 'round' : 'rounds' }} left
                        <template v-if="run.odds.winsForPrize">
                            <span class="mx-0.5">&middot;</span>
                            <span class="font-medium text-foreground">
                                {{ run.odds.winsForPrize }} {{ run.odds.winsForPrize === 1 ? 'win' : 'wins' }}
                            </span>
                            for prize
                        </template>
                    </span>
                    <span
                        v-else-if="run.tixDelta !== null"
                        class="font-medium tabular-nums"
                        :class="run.tixDelta >= 0 ? 'text-success' : 'text-destructive'"
                    >
                        {{ run.tixDelta > 0 ? '+' : '' }}{{ run.tixDelta }} tix
                    </span>
                </div>
            </div>

            <Button
                variant="ghost"
                size="icon"
                class="size-6 shrink-0"
                :disabled="capturing"
                aria-label="Copy screenshot of league"
                @click.stop="copyScreenshot"
            >
                <Camera class="size-3.5" />
            </Button>
        </div>

        <CardContent class="flex flex-col p-0">
            <div class="border-t px-4 py-3">
                <RunSummaryStats
                    :game-wins="run.gameWins"
                    :game-losses="run.gameLosses"
                    :on-play-record="run.onPlayRecord"
                    :on-draw-record="run.onDrawRecord"
                />
            </div>

            <div v-if="run.matches.length" class="divide-y divide-border/60 border-t">
                <button
                    v-for="(match, index) in run.matches"
                    :key="match.id"
                    type="button"
                    class="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm transition-colors hover:bg-muted/40"
                    @click="router.visit(MatchShowController({ id: match.id }).url)"
                >
                    <span class="w-3 shrink-0 text-xs text-muted-foreground tabular-nums">{{ index + 1 }}</span>
                    <ResultBadge :won="match.result === 'W'" />
                    <span class="shrink-0 font-medium">{{ match.opponentName ?? 'Unknown' }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{ match.opponentArchetype ?? '' }}</span>
                    <span class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums">{{ gameRecord(match.gameResults) }}</span>
                </button>
            </div>

            <div v-if="live && run.odds && run.odds.chanceOfPrize !== null" class="border-t px-4 py-3">
                <p class="text-xs text-muted-foreground">Expected finish from {{ wins }}&ndash;{{ losses }}</p>
                <p class="mt-1 text-sm tabular-nums">
                    <span class="font-semibold text-success">{{ run.odds.chanceOfPrize }}%</span>
                    <span class="text-muted-foreground"> to reach {{ run.odds.prizeWins }} wins or better</span>
                    <template v-if="run.odds.expectedTix !== null">
                        <span class="mx-1 text-muted-foreground">&middot;</span>
                        <span class="font-semibold" :class="run.odds.expectedTix >= 0 ? 'text-warning' : 'text-destructive'">
                            {{ run.odds.expectedTix > 0 ? '+' : '' }}{{ run.odds.expectedTix }}
                        </span>
                        <span class="text-muted-foreground"> tix EV</span>
                    </template>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">Projected {{ oddsNote }}.</p>
            </div>
        </CardContent>

    </Card>

    <div v-if="showScreenshot" style="position: fixed; top: -9999px; left: -9999px; pointer-events: none;">
        <LeagueScreenshot ref="screenshotRef" :league="run" />
    </div>
</template>
