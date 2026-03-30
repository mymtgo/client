<script setup lang="ts">
import { ref } from 'vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Badge } from '@/components/ui/badge';
import ManaSymbols from '@/components/ManaSymbols.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import MatchNotesDialog from '@/components/matches/MatchNotesDialog.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useForm, router } from '@inertiajs/vue3';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import DetectArchetypeController from '@/actions/App/Http/Controllers/Matches/DetectArchetypeController';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import { useToast } from '@/composables/useToast';
import { NotepadText, RefreshCw } from 'lucide-vue-next';

const props = defineProps<{
    matches: App.Data.Front.MatchData[];
    archetypes?: App.Data.Front.ArchetypeData[];
}>();

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);
const notesDialog = ref<InstanceType<typeof MatchNotesDialog> | null>(null);
const detectingMatchId = ref<number | null>(null);
const { add: toast } = useToast();

const deleteForm = useForm<{
    id: string | number;
}>({
    id: '',
});

const deleteMatch = (id: string | number) => {
    deleteForm.id = id;

    deleteForm.submit(DeleteController({ id }), {
        preserveScroll: true,
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

const detectArchetype = (matchId: number) => {
    detectingMatchId.value = matchId;

    router.post(
        DetectArchetypeController({ id: matchId }).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                const match = props.matches.find((m) => m.id === matchId);
                if (!match?.opponentArchetypes?.[0]?.archetype) {
                    toast({
                        type: 'error',
                        title: 'Detection failed',
                        message: "Could not determine the opponent's archetype for this match.",
                    });
                }
            },
            onFinish: () => {
                detectingMatchId.value = null;
            },
        },
    );
};
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes ?? []" />
    <MatchNotesDialog ref="notesDialog" />

    <Table>
        <TableHeader class="sticky top-0 z-10 backdrop-blur-sm">
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
            <template v-for="match in matches" :key="match.id">
                <ContextMenu>
                    <ContextMenuTrigger asChild>
                        <TableRow class="cursor-pointer" @click="router.visit(ShowController({ id: match.id }).url)">
                            <TableCell>
                                <ResultBadge :won="match.gamesWon > match.gamesLost" v-if="match.gamesWon !== match.gamesLost" :showText="true" />
                            </TableCell>
                            <TableCell>
                                <span v-if="match.leagueGame">League</span>
                                <span v-if="!match.leagueGame">Casual</span>
                            </TableCell>
                            <TableCell class="font-medium">
                                <span v-if="match.opponentName">{{ match.opponentName }}</span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-1" v-if="match.opponentArchetypes?.[0]?.archetype">
                                    {{ match.opponentArchetypes[0].archetype.name }}
                                </div>
                                <span v-else-if="detectingMatchId === match.id" class="text-muted-foreground">
                                    <RefreshCw class="size-3.5 animate-spin" />
                                </span>
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
                                {{ match.startedAtFormatted }}
                            </TableCell>
                            <TableCell>
                                <TooltipProvider v-if="match.notes">
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <NotepadText :size="14" class="text-muted-foreground" />
                                        </TooltipTrigger>
                                        <TooltipContent side="left" class="max-w-xs">
                                            <p class="text-xs whitespace-pre-wrap">{{ match.notes }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </TableCell>
                        </TableRow>
                    </ContextMenuTrigger>
                    <ContextMenuContent>
                        <ContextMenuItem @click="notesDialog?.openForMatch(match.id, match.notes ?? null)">
                            {{ match.notes ? 'Edit notes' : 'Add notes' }}
                        </ContextMenuItem>
                        <ContextMenuItem @click="detectArchetype(match.id)">Detect archetype</ContextMenuItem>
                        <ContextMenuItem @click="archetypeDialog?.openForMatch(match.id, match.format)">Set manual archetype</ContextMenuItem>
                        <ContextMenuItem v-if="match.opponentArchetypes?.[0]?.archetype" @click="clearArchetype(match.id)">
                            Clear archetype
                        </ContextMenuItem>
                        <ContextMenuItem @click="deleteMatch(match.id)">Remove from stats</ContextMenuItem>
                    </ContextMenuContent>
                </ContextMenu>
            </template>
        </TableBody>
    </Table>
</template>
