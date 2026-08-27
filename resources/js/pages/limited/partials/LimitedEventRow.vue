<script setup lang="ts">
import DeckController from '@/actions/App/Http/Controllers/Limited/DeckController';
import DraftController from '@/actions/App/Http/Controllers/Limited/DraftController';
import LimitedMatchController from '@/actions/App/Http/Controllers/Limited/MatchController';
import RunMatchesTable from '@/components/leagues/RunMatchesTable.vue';
import RunSummaryStats from '@/components/leagues/RunSummaryStats.vue';
import StateBadge from '@/components/limited/StateBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NO_VALUE, formatSeconds, type LimitedIndexRow } from '@/types/limited';
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Calendar, ChevronDown, Timer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ row: LimitedIndexRow; defaultExpanded?: boolean }>(), { defaultExpanded: false });

/** Nothing to expand into until the run has played a match. */
const canExpand = computed(() => props.row.matches.length > 0);

/** Same rule as LeagueCard: the newest run, and any live run, open on arrival. */
const expanded = ref(canExpand.value && (props.defaultExpanded || props.row.stateVariant === 'default'));

/**
 * Limited matches carry no archetype: there is no metagame corpus for a
 * 40-card pool, and detection is skipped for them entirely.
 */
const matchUrlFor = (matchId: number): string => LimitedMatchController.url({ league: props.row.leagueId as number, match: matchId });

const scoreClass = computed(() => {
    if (!props.row.linked || props.row.wins + props.row.losses === 0) return 'from-zinc-600 to-zinc-800';
    if (props.row.wins > props.row.losses) return 'from-emerald-500 to-emerald-700';
    if (props.row.wins === props.row.losses) return 'from-amber-500 to-amber-700';
    return 'from-rose-500 to-rose-700';
});

const scoreLabel = computed(() => {
    if (!props.row.linked) return NO_VALUE;
    return `${props.row.wins}-${props.row.losses}`;
});

const draftHref = computed(() => (props.row.leagueId ? DraftController.url({ league: props.row.leagueId }) : null));
const deckHref = computed(() => (props.row.leagueId ? DeckController.url({ league: props.row.leagueId }) : null));
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <div class="flex items-center gap-4 px-4 py-3">
            <div
                class="flex size-12 shrink-0 items-center justify-center rounded-md bg-linear-to-b text-lg font-bold text-white tabular-nums shadow-inner shadow-black/40"
                :class="scoreClass"
            >
                {{ scoreLabel }}
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <Link v-if="draftHref" :href="draftHref" prefetch class="font-semibold underline-offset-2 hover:underline">{{ row.title }}</Link>
                    <span v-else class="font-semibold">{{ row.title }}</span>
                    <Badge v-if="row.setCode" variant="secondary">{{ row.setCode }}</Badge>
                    <Badge variant="outline" class="capitalize">{{ row.kind }}</Badge>
                    <StateBadge :label="row.state" :variant="row.stateVariant" />
                    <span class="inline-flex items-center gap-1">
                        <span
                            v-for="(result, index) in row.results"
                            :key="index"
                            class="inline-block size-2.5 rounded-full"
                            :class="result === 'W' ? 'bg-emerald-400' : result === 'L' ? 'bg-rose-400' : 'ring-1 ring-white/30'"
                        />
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                    <span class="inline-flex items-center gap-1"><Calendar class="size-3" />{{ row.startedAtHuman }}</span>
                    <span>{{ row.picksMade }}/{{ row.picksExpected }} picks</span>
                    <span v-if="row.deckRegistered">{{ row.versionCount }} deck version{{ row.versionCount === 1 ? '' : 's' }}</span>
                    <span v-if="row.avgPickSeconds !== null" class="inline-flex items-center gap-1">
                        <Timer class="size-3" />avg {{ formatSeconds(row.avgPickSeconds) }} / pick
                    </span>
                </div>

                <div v-if="row.opponents.length" class="text-xs text-muted-foreground">vs {{ row.opponents.join(' · ') }}</div>
                <div v-else-if="row.note" class="text-xs text-muted-foreground/70 italic">{{ row.note }}</div>
            </div>

            <div v-if="row.linked" class="flex shrink-0 items-center gap-2">
                <Button v-if="deckHref" as-child variant="outline" size="sm"><Link :href="deckHref" prefetch>Deck</Link></Button>
                <Button v-if="draftHref" as-child size="sm">
                    <Link :href="draftHref" prefetch>Review draft <ArrowRight class="size-3.5" /></Link>
                </Button>
                <Button
                    v-if="canExpand"
                    variant="ghost"
                    size="icon"
                    class="size-8 text-muted-foreground"
                    :aria-label="expanded ? 'Hide matches' : 'Show matches'"
                    :aria-expanded="expanded"
                    @click="expanded = !expanded"
                >
                    <ChevronDown class="size-4 transition-transform" :class="{ 'rotate-180': expanded }" />
                </Button>
            </div>
        </div>

        <div v-if="expanded && canExpand" class="flex flex-col gap-4 border-t border-border p-4">
            <RunSummaryStats
                :game-wins="row.gameWins"
                :game-losses="row.gameLosses"
                :on-play-record="row.onPlayRecord"
                :on-draw-record="row.onDrawRecord"
            />
            <RunMatchesTable :matches="row.matches" :format="row.kind" :show-archetype="false" :match-url="matchUrlFor" />
        </div>
    </Card>
</template>
