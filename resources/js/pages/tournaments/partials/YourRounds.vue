<script setup lang="ts">
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/vue3';

type Round = {
    match_id: number;
    round: number;
    opponent_username: string | null;
    opponent_login_id: number | null;
    opponent_rank: number | null;
    result: string;
    deck_name: string | null;
    deck_id: number | null;
};

const props = defineProps<{
    rounds: Round[];
    eliminatedAfterRound: number | null;
}>();

function opponentDisplay(round: Round): string {
    if (round.opponent_username) {
        return round.opponent_username;
    }
    return round.opponent_login_id ? `Player #${round.opponent_login_id}` : 'Unknown opponent';
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <h2 class="text-sm font-semibold text-zinc-300">Your Rounds</h2>

        <div v-if="rounds.length === 0" class="py-6 text-center text-sm text-zinc-500">No rounds played yet.</div>

        <template v-else>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-16">Round</TableHead>
                        <TableHead>Opponent</TableHead>
                        <TableHead class="w-16">Rank</TableHead>
                        <TableHead class="w-20">Result</TableHead>
                        <TableHead>Deck</TableHead>
                        <TableHead class="w-24"></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="round in rounds" :key="round.match_id">
                        <TableCell class="tabular-nums text-sm text-zinc-400">{{ round.round }}</TableCell>
                        <TableCell class="text-sm">{{ opponentDisplay(round) }}</TableCell>
                        <TableCell class="tabular-nums text-sm text-zinc-400">
                            {{ round.opponent_rank !== null ? `#${round.opponent_rank}` : '—' }}
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">{{ round.result }}</TableCell>
                        <TableCell class="text-sm text-zinc-400">{{ round.deck_name ?? '—' }}</TableCell>
                        <TableCell>
                            <Link
                                :href="MatchShowController.url({ id: round.match_id })"
                                class="text-xs text-blue-400 hover:underline"
                            >
                                View match
                            </Link>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <p v-if="eliminatedAfterRound !== null" class="text-xs text-red-400">
                Eliminated after round {{ eliminatedAfterRound }}
            </p>
        </template>
    </div>
</template>
