<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchupDetailController from '@/actions/App/Http/Controllers/Decks/MatchupDetailController';
import { ref, watch } from 'vue';
import type { MatchupDetail, MatchupSpread } from '@/types/decks';

const props = defineProps<{
    deckId: number;
    matchup: MatchupSpread | null;
    timeframe: string;
    version: number | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const isOpen = ref(false);
const loading = ref(false);
const detail = ref<MatchupDetail | null>(null);
watch(() => props.matchup, async (matchup) => {
    if (matchup === null) {
        isOpen.value = false;
        return;
    }

    isOpen.value = true;
    loading.value = true;
    try {
        const params: Record<string, string> = {};
        if (props.timeframe !== 'alltime') params.timeframe = props.timeframe;
        if (props.version) params.version = String(props.version);

        const url = MatchupDetailController.url({ deck: props.deckId, archetype: matchup.archetype_id });
        const query = new URLSearchParams(params).toString();
        const response = await fetch(query ? `${url}?${query}` : url);
        detail.value = await response.json();
    } finally {
        loading.value = false;
    }
});

function onOpenChange(open: boolean) {
    if (!open) {
        emit('close');
    }
}

function winrateColor(rate: number): string {
    if (rate > 50) return 'text-success';
    if (rate < 50) return 'text-destructive';
    return '';
}

function barColor(rate: number): string {
    if (rate > 50) return 'bg-success';
    if (rate < 50) return 'bg-destructive';
    return 'bg-muted-foreground';
}
</script>

<template>
    <Sheet :open="isOpen" @update:open="onOpenChange">
        <SheetContent side="right" class="w-[560px] overflow-y-auto sm:max-w-[560px]">
            <SheetHeader v-if="matchup">
                <div class="flex items-center gap-2">
                    <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                    <SheetTitle>{{ matchup.name }}</SheetTitle>
                </div>
                <div class="flex gap-4 text-sm text-muted-foreground">
                    <span>Record: <span class="font-medium" :class="winrateColor(matchup.match_winrate)">{{ matchup.match_record }}</span></span>
                    <span>Win Rate: <span class="font-medium" :class="winrateColor(matchup.match_winrate)">{{ matchup.match_winrate }}%</span></span>
                    <span>{{ matchup.matches }} matches</span>
                </div>
            </SheetHeader>

            <!-- Loading state -->
            <div v-if="loading" class="flex flex-col gap-4 p-6">
                <div class="h-6 w-48 animate-pulse rounded bg-muted" />
                <div class="h-4 w-32 animate-pulse rounded bg-muted" />
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="h-24 animate-pulse rounded-lg bg-muted" />
                    <div class="h-24 animate-pulse rounded-lg bg-muted" />
                </div>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    <div class="h-24 animate-pulse rounded-lg bg-muted" />
                    <div class="h-24 animate-pulse rounded-lg bg-muted" />
                </div>
                <div class="mt-2 grid grid-cols-3 gap-3">
                    <div class="h-20 animate-pulse rounded-lg bg-muted" />
                    <div class="h-20 animate-pulse rounded-lg bg-muted" />
                    <div class="h-20 animate-pulse rounded-lg bg-muted" />
                </div>
            </div>

            <!-- Content -->
            <div v-else-if="detail" class="flex flex-col gap-0">
                <!-- Per-Game Win Rates -->
                <div class="border-b border-border px-6 py-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Win Rate by Game</h3>
                    <div class="grid gap-3" :class="detail.perGameWinrates.length === 3 ? 'grid-cols-3' : 'grid-cols-2'">
                        <div
                            v-for="pg in detail.perGameWinrates"
                            :key="pg.gameNumber"
                            class="rounded-lg border border-border bg-card p-3"
                        >
                            <div class="text-xs uppercase tracking-wide text-muted-foreground">Game {{ pg.gameNumber }}</div>
                            <div class="mt-1.5 text-2xl font-semibold tabular-nums" :class="winrateColor(pg.winrate)">
                                {{ pg.winrate }}%
                            </div>
                            <div class="mt-0.5 text-xs text-muted-foreground tabular-nums">{{ pg.record }}</div>
                        </div>
                    </div>
                </div>

                <!-- Play / Draw -->
                <div class="border-b border-border px-6 py-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Play / Draw Breakdown</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg border border-border bg-card p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-xs font-medium text-muted-foreground">On the Play</span>
                                <span class="text-lg font-semibold tabular-nums" :class="winrateColor(detail.otpWinrate)">
                                    {{ detail.otpWinrate }}%
                                </span>
                            </div>
                            <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full transition-all" :class="barColor(detail.otpWinrate)" :style="{ width: detail.otpWinrate + '%' }" />
                            </div>
                            <div class="text-xs text-muted-foreground tabular-nums">{{ detail.otpRecord }} games</div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-xs font-medium text-muted-foreground">On the Draw</span>
                                <span class="text-lg font-semibold tabular-nums" :class="winrateColor(detail.otdWinrate)">
                                    {{ detail.otdWinrate }}%
                                </span>
                            </div>
                            <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full transition-all" :class="barColor(detail.otdWinrate)" :style="{ width: detail.otdWinrate + '%' }" />
                            </div>
                            <div class="text-xs text-muted-foreground tabular-nums">{{ detail.otdRecord }} games</div>
                        </div>
                    </div>
                </div>

                <!-- Game Stats -->
                <div class="border-b border-border px-6 py-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Game Stats</h3>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-lg border border-border bg-card p-3 text-center">
                            <div class="text-xl font-semibold tabular-nums">{{ detail.avgTurns ?? '—' }}</div>
                            <div class="mt-1 text-xs uppercase tracking-wide text-muted-foreground">Avg Turns</div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-3 text-center">
                            <div class="text-xl font-semibold tabular-nums">{{ detail.avgMulligans ?? '—' }}</div>
                            <div class="mt-1 text-xs uppercase tracking-wide text-muted-foreground">Avg Mulligans</div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-3 text-center">
                            <div class="text-xl font-semibold tabular-nums">{{ detail.onPlayRate }}%</div>
                            <div class="mt-1 text-xs uppercase tracking-wide text-muted-foreground">On Play Rate</div>
                        </div>
                    </div>
                </div>

                <!-- Match History -->
                <div class="px-6 py-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Match History</h3>
                    <div class="flex flex-col">
                        <div
                            v-for="match in detail.matchHistory"
                            :key="match.id"
                            class="flex items-center gap-3 border-b border-border py-2 last:border-b-0"
                        >
                            <span class="w-12 shrink-0 text-xs text-muted-foreground">{{ match.dateFormatted }}</span>
                            <span class="min-w-0 flex-1 truncate text-xs">{{ match.opponentName }}</span>
                            <span class="flex shrink-0 gap-1">
                                <span
                                    v-for="i in 3"
                                    :key="i"
                                    class="size-2 rounded-full"
                                    :class="
                                        match.gameResults[i - 1] === true ? 'bg-success' :
                                        match.gameResults[i - 1] === false ? 'bg-destructive' :
                                        'border border-muted-foreground/30'
                                    "
                                />
                            </span>
                            <span
                                class="w-8 shrink-0 text-right text-sm font-semibold tabular-nums"
                                :class="match.outcome === 'win' ? 'text-success' : match.outcome === 'loss' ? 'text-destructive' : ''"
                            >
                                {{ match.score }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
