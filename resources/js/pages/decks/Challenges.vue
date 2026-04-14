<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import { Link } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import ShowController from '@/actions/App/Http/Controllers/Challenges/ShowController';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

type Challenge = {
    id: number;
    name: string | null;
    format: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    started_at: string | null;
};

type LocalStanding = {
    challenge_id: number;
    rank: number;
    round: number;
};

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    challenges: Challenge[];
    localStandings: Record<number, LocalStanding>;
}>();

function stateLabel(state: string): string {
    if (state === 'completed') return 'Completed';
    return 'In Progress';
}

function stateColor(state: string): string {
    return state === 'completed' ? 'text-zinc-400' : 'text-green-500';
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <div v-if="challenges.length === 0" class="py-8 text-center text-sm text-zinc-500">
            No challenges found for this deck. Challenges will appear here once you participate in a challenge with this deck.
        </div>

        <Card v-if="challenges.length > 0">
            <CardContent class="p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-800 text-left text-xs text-zinc-500">
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Format</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Round</th>
                            <th class="px-3 py-2">Your Rank</th>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="challenge in challenges"
                            :key="challenge.id"
                            class="border-b border-zinc-800/50 hover:bg-zinc-800/30"
                        >
                            <td class="px-3 py-2">{{ challenge.name || 'Challenge' }}</td>
                            <td class="px-3 py-2">{{ challenge.format || '-' }}</td>
                            <td class="px-3 py-2">
                                <span :class="stateColor(challenge.state)" class="text-xs font-medium">
                                    {{ stateLabel(challenge.state) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="challenge.current_round">
                                    {{ challenge.current_round }}<span v-if="challenge.max_rounds">/{{ challenge.max_rounds }}</span>
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="localStandings[challenge.id]">
                                    #{{ localStandings[challenge.id].rank }}
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2 text-zinc-400">
                                {{ challenge.started_at ? new Date(challenge.started_at).toLocaleDateString() : '-' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Link
                                    :href="ShowController.url({ challenge: challenge.id }) + `?from=deck&deck_id=${deck.id}`"
                                    class="text-xs text-blue-400 hover:text-blue-300"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
