<script setup lang="ts">
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Badge } from '@/components/ui/badge';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import dayjs from 'dayjs';
import { useForm } from '@inertiajs/vue3';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';

defineProps<{
    matches: App.Data.Front.MatchData[];
}>();

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
</script>

<template>
    <Table>
        <TableHeader class="bg-muted">
            <TableRow>
                <TableHead>Result</TableHead>
                <TableHead>Type</TableHead>
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
                                <Badge variant="secondary" class="bg-green-500 text-black" v-if="match.gamesWon > match.gamesLost"> Win </Badge>
                                <Badge variant="destructive" v-if="match.gamesWon < match.gamesLost"> Loss </Badge>
                            </TableCell>
                            <TableCell>
                                <span v-if="match.leagueGame">League</span>
                                <span v-if="!match.leagueGame">Casual</span>
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-1" v-if="match.opponentArchetypes">
                                    {{ match.opponentArchetypes[0].archetype.name }}
                                </div>
                                <span v-if="!match.opponentArchetypes[0]" class="opacity-50"> Unknown</span>
                            </TableCell>
                            <TableCell>
                                <div v-if="match.opponentArchetypes">
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
                                <!--                                        <Button size="sm" variant="outline" @click="router.visit(ShowController({ id: match.id }).url)">View</Button>-->
                            </TableCell>
                        </TableRow>
                    </ContextMenuTrigger>
                    <ContextMenuContent>
                        <ContextMenuItem @click="deleteMatch(match.id)">Remove from stats</ContextMenuItem>
                    </ContextMenuContent>
                </ContextMenu>
            </template>
        </TableBody>
    </Table>
</template>

<style scoped></style>
