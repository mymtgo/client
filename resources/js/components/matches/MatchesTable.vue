<script setup lang="ts">
import { ref } from 'vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Badge } from '@/components/ui/badge';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import dayjs from 'dayjs';
import { useForm, router } from '@inertiajs/vue3';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import { Button } from '@/components/ui/button';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';

defineProps<{
    matches: App.Data.Front.MatchData[];
    archetypes?: App.Data.Front.ArchetypeData[];
}>();

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);

const deleteForm = useForm<{
    id: string | number;
}>({
    id: '',
});

const deleteMatch = (id: string | number) => {
    deleteForm.id = id;

    deleteForm.submit(DeleteController({ id }), {
        onSuccess: () => deleteForm.reset(),
    });
};

const clearArchetypeForm = useForm<{ archetype_id: null }>({
    archetype_id: null,
});

const clearArchetype = (matchId: number) => {
    clearArchetypeForm.submit(UpdateArchetypeController({ id: matchId }), {
        onSuccess: () => clearArchetypeForm.reset(),
    });
};
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes ?? []" />

    <Table>
        <TableHeader class="bg-muted">
            <TableRow>
                <TableHead>Result</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Opponent</TableHead>
                <TableHead>Archetype</TableHead>
                <TableHead></TableHead>
                <TableHead>Games won</TableHead>
                <TableHead>Games lost</TableHead>
                <TableHead>Duration</TableHead>
                <TableHead>Date</TableHead>
                <TableHead></TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <template v-for="(match, idx) in matches" :key="`match_${idx}`">
                <ContextMenu>
                    <ContextMenuTrigger asChild>
                        <TableRow>
                            <TableCell>
                                <Badge class="border-transparent bg-win text-win-foreground" v-if="match.gamesWon > match.gamesLost"> Win </Badge>
                                <Badge variant="destructive" v-if="match.gamesWon < match.gamesLost"> Loss </Badge>
                            </TableCell>
                            <TableCell>
                                <span v-if="match.leagueGame">League</span>
                                <span v-if="!match.leagueGame">Casual</span>
                            </TableCell>
                            <TableCell class="font-medium">
                                <!-- TODO: wire up opponent username from MatchData once added to DTO -->
                                <span class="text-muted-foreground text-xs">—</span>
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-1" v-if="match.opponentArchetypes?.[0]?.archetype">
                                    {{ match.opponentArchetypes[0].archetype.name }}
                                </div>
                                <span v-else class="text-muted-foreground">Unknown</span>
                            </TableCell>
                            <TableCell>
                                <div v-if="match.opponentArchetypes?.[0]?.archetype">
                                    <ManaSymbols :symbols="match.opponentArchetypes[0].archetype.colorIdentity" />
                                </div>
                            </TableCell>
                            <TableCell>
                                {{ match.gamesWon }}
                            </TableCell>
                            <TableCell>
                                {{ match.gamesLost }}
                            </TableCell>
                            <TableCell>
                                {{ match.matchTime }}
                            </TableCell>
                            <TableCell>
                                {{ dayjs(match.startedAt).format('DD/MM/YYYY hh:mma') }}
                            </TableCell>

                            <TableCell>
                                <Button size="sm" variant="ghost" @click="router.visit(ShowController({ id: match.id }).url)">View</Button>
                            </TableCell>
                        </TableRow>
                    </ContextMenuTrigger>
                    <ContextMenuContent>
                        <ContextMenuItem @click="archetypeDialog?.openForMatch(match.id, match.format)">Set archetype</ContextMenuItem>
                        <ContextMenuItem
                            v-if="match.opponentArchetypes?.[0]?.archetype"
                            @click="clearArchetype(match.id)"
                        >
                            Clear archetype
                        </ContextMenuItem>
                        <ContextMenuItem @click="deleteMatch(match.id)">Remove from stats</ContextMenuItem>
                    </ContextMenuContent>
                </ContextMenu>
            </template>
        </TableBody>
    </Table>
</template>
