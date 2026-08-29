<script setup lang="ts">
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import MatchRowMenu from '@/components/matches/MatchRowMenu.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { LeagueMatch } from '@/types/leagues';
import { NO_VALUE } from '@/types/limited';
import { router } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

/**
 * The matches of a single league run: a handful of rows, fixed order, no
 * sorting. MatchesTable is the sortable full-history table and stays separate.
 */
const props = withDefaults(
    defineProps<{
        matches: LeagueMatch[];
        format: string;
        archetypes?: App.Data.Front.ArchetypeData[];
        /** Limited runs have no archetype to show or set. */
        showArchetype?: boolean;
        /** Manual leagues can drop a match back out of the run. */
        canUnlink?: boolean;
        matchUrl?: (matchId: number) => string;
    }>(),
    { archetypes: () => [], showArchetype: true, canUnlink: false, matchUrl: undefined },
);

const emit = defineEmits<{ unlink: [matchId: number] }>();

const urlFor = (matchId: number): string => (props.matchUrl ?? ((id: number) => MatchShowController({ id }).url))(matchId);

function formatDuration(seconds: number | null): string {
    if (!seconds) return NO_VALUE;
    return `${Math.round(seconds / 60)}m`;
}
</script>

<template>
    <div class="isolate overflow-hidden rounded-md border border-border bg-card">
        <Table class="table-fixed">
            <TableHeader class="!static !backdrop-blur-none">
                <TableRow>
                    <TableHead class="w-[60px] text-center">Match</TableHead>
                    <TableHead class="w-[110px]">Result</TableHead>
                    <TableHead class="w-[160px]">Opponent</TableHead>
                    <TableHead v-if="showArchetype">Vs</TableHead>
                    <!-- A draft seat has no archetype; its colours are the only label. -->
                    <TableHead v-else>Colours</TableHead>
                    <TableHead class="w-[120px] text-center">Game 1</TableHead>
                    <TableHead class="w-[120px] text-center">Game 2</TableHead>
                    <TableHead class="w-[120px] text-center">Game 3</TableHead>
                    <TableHead class="w-[80px] text-right">Time</TableHead>
                    <TableHead v-if="canUnlink" class="w-[60px]"></TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <MatchRowMenu
                    v-for="(match, matchIndex) in matches"
                    :key="match.id"
                    :match-id="match.id"
                    :format="format"
                    :current-archetype-id="match.opponentArchetypeId"
                    :notes="match.notes"
                    :archetypes="archetypes"
                    :show-archetype="showArchetype"
                >
                    <TableRow class="cursor-pointer" @click="router.visit(urlFor(match.id))">
                        <TableCell class="text-center text-sm text-muted-foreground tabular-nums">
                            {{ matchIndex + 1 }}
                        </TableCell>
                        <TableCell>
                            <ResultBadge :won="match.result === 'W'" :show-text="true" />
                        </TableCell>
                        <TableCell class="truncate font-medium">
                            <span v-if="match.opponentName">{{ match.opponentName }}</span>
                            <span v-else class="text-muted-foreground">{{ NO_VALUE }}</span>
                        </TableCell>
                        <TableCell v-if="showArchetype" class="truncate">
                            <span v-if="match.opponentArchetype" class="text-sm">{{ match.opponentArchetype }}</span>
                            <span v-else class="text-xs text-muted-foreground">Unknown</span>
                        </TableCell>
                        <TableCell v-else>
                            <ManaSymbols v-if="match.opponentColors" :symbols="match.opponentColors" />
                            <span v-else class="text-xs text-muted-foreground">{{ NO_VALUE }}</span>
                        </TableCell>
                        <TableCell v-for="i in 3" :key="i" class="text-center text-sm">
                            <template v-if="match.gameResults[i - 1]">
                                <span :class="match.gameResults[i - 1].result === 'W' ? 'text-success' : 'text-destructive'">
                                    {{ match.gameResults[i - 1].result === 'W' ? 'Win' : 'Loss' }}
                                </span>
                                <span v-if="match.gameResults[i - 1].onPlay !== null" class="ml-1 text-xs text-muted-foreground">
                                    ({{ match.gameResults[i - 1].onPlay ? 'OTP' : 'OTD' }})
                                </span>
                            </template>
                            <span v-else class="text-muted-foreground">{{ NO_VALUE }}</span>
                        </TableCell>
                        <TableCell class="text-right text-muted-foreground tabular-nums">
                            {{ formatDuration(match.durationSeconds) }}
                        </TableCell>
                        <TableCell v-if="canUnlink" class="text-right">
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-7 text-muted-foreground hover:text-destructive"
                                title="Remove from league"
                                @click.stop="emit('unlink', match.id)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </TableCell>
                    </TableRow>
                </MatchRowMenu>
            </TableBody>
        </Table>
    </div>
</template>
