<script setup lang="ts">
import { ref, computed } from 'vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';import { Checkbox } from '@/components/ui/checkbox';
import ManaSymbols from '@/components/ManaSymbols.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import MatchNotesDialog from '@/components/matches/MatchNotesDialog.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Button } from '@/components/ui/button';
import { useForm, router } from '@inertiajs/vue3';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import DetectArchetypeController from '@/actions/App/Http/Controllers/Matches/DetectArchetypeController';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import { useToast } from '@/composables/useToast';
import { NotepadText, RefreshCw, Tags, X } from 'lucide-vue-next';
import SortableHeader from '@/components/SortableHeader.vue';

const props = defineProps<{
    matches: App.Data.Front.MatchData[];
    archetypes?: App.Data.Front.ArchetypeData[];
    sortBy?: string | null;
    sortDir?: 'asc' | 'desc';
}>();

const emit = defineEmits<{
    sort: [column: string];
}>();

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);
const notesDialog = ref<InstanceType<typeof MatchNotesDialog> | null>(null);
const detectingMatchId = ref<number | null>(null);
const { add: toast } = useToast();

const selectedIds = ref<number[]>([]);

const allSelected = computed(() => {
    return props.matches.length > 0 && selectedIds.value.length === props.matches.length;
});

const someSelected = computed(() => {
    return selectedIds.value.length > 0 && selectedIds.value.length < props.matches.length;
});

const toggleAll = (checked: boolean | 'indeterminate') => {
    if (checked === true) {
        selectedIds.value = props.matches.map((m) => m.id);
    } else {
        selectedIds.value = [];
    }
};

const toggleMatch = (matchId: number, checked: boolean | 'indeterminate') => {
    if (checked === true) {
        selectedIds.value = [...selectedIds.value, matchId];
    } else {
        selectedIds.value = selectedIds.value.filter((id) => id !== matchId);
    }
};

const clearSelection = () => {
    selectedIds.value = [];
};

const openBulkSetArchetype = () => {
    const formats = new Set(props.matches.filter((m) => selectedIds.value.includes(m.id)).map((m) => m.format));
    const format = formats.size === 1 ? [...formats][0] : null;
    archetypeDialog.value?.openForMatches([...selectedIds.value], format);
};

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
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes ?? []" @archetype-set="clearSelection" />
    <MatchNotesDialog ref="notesDialog" />

    <div v-if="selectedIds.length > 0" class="flex items-center gap-3 border-b bg-muted/50 px-4 py-2">
        <span class="text-sm font-medium">{{ selectedIds.length }} selected</span>

        <Button variant="outline" size="sm" class="gap-1.5" @click="openBulkSetArchetype">
            <Tags class="size-3.5" />
            Set archetype
        </Button>

        <Button variant="ghost" size="sm" class="ml-auto gap-1.5 text-muted-foreground" @click="clearSelection">
            <X class="size-3.5" />
            Clear
        </Button>
    </div>

    <Table>
        <TableHeader class="sticky top-0 z-10 backdrop-blur-sm">
            <TableRow>
                <TableHead class="w-10">
                    <Checkbox
                        :model-value="allSelected ? true : someSelected ? 'indeterminate' : false"
                        @update:model-value="toggleAll"
                    />
                </TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'outcome')">
                    <SortableHeader label="Result" column="outcome" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead>Opponent</TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'archetype')">
                    <SortableHeader label="Archetype" column="archetype" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead></TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'game_1')">
                    <SortableHeader label="Game 1" column="game_1" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'game_2')">
                    <SortableHeader label="Game 2" column="game_2" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'game_3')">
                    <SortableHeader label="Game 3" column="game_3" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'duration')">
                    <SortableHeader label="Duration" column="duration" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead class="cursor-pointer select-none" @click="emit('sort', 'started_at')">
                    <SortableHeader label="Date" column="started_at" :sort-by="sortBy" :sort-dir="sortDir" />
                </TableHead>
                <TableHead></TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <template v-for="match in matches" :key="match.id">
                <ContextMenu>
                    <ContextMenuTrigger asChild>
                        <TableRow
                            class="cursor-pointer"
                            :data-state="selectedIds.includes(match.id) ? 'selected' : undefined"
                            @click="router.visit(ShowController({ id: match.id }).url)"
                        >
                            <TableCell @click.stop>
                                <Checkbox
                                    :model-value="selectedIds.includes(match.id)"
                                    @update:model-value="(val) => toggleMatch(match.id, val)"
                                />
                            </TableCell>
                            <TableCell>
                                <ResultBadge :won="match.gamesWon > match.gamesLost" v-if="match.gamesWon !== match.gamesLost" :showText="true" />
                            </TableCell>
                            <TableCell class="font-medium">
                                <span v-if="match.opponentName">{{ match.opponentName }}</span>
                                <span v-else class="text-xs text-muted-foreground">&mdash;</span>
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
                            <TableCell v-for="gameIdx in 3" :key="gameIdx" class="text-sm">
                                <template v-if="match.gameResults?.[gameIdx - 1]">
                                    <span :class="match.gameResults[gameIdx - 1].result === 'W' ? 'text-success' : 'text-destructive'">
                                        {{ match.gameResults[gameIdx - 1].result === 'W' ? 'Win' : 'Loss' }}
                                    </span>
                                    <span v-if="match.gameResults[gameIdx - 1].onPlay !== null" class="text-xs text-muted-foreground">
                                        ({{ match.gameResults[gameIdx - 1].onPlay ? 'OTP' : 'OTD' }})
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">&mdash;</span>
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
