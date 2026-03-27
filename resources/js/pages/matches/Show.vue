<script setup lang="ts">
import { computed, ref, nextTick } from 'vue';
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import { Button } from '@/components/ui/button';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import MatchGame from '@/pages/matches/partials/MatchGame.vue';
import UpdateNotesController from '@/actions/App/Http/Controllers/Matches/UpdateNotesController';
import { Card, CardContent } from '@/components/ui/card';
import { AlertTriangle, PencilIcon, NotepadText } from 'lucide-vue-next';
import { useForm } from '@inertiajs/vue3';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

type GameDetail = {
    id: number;
    number: number;
    won: boolean;
    onThePlay: boolean;
    duration: string | null;
    turns: number | null;
    localMulligans: number;
    opponentMulligans: number;
    mulliganedHands: { name: string; image: string | null }[][];
    keptHand: { name: string; image: string | null; bottomed: boolean }[];
    sideboardChanges: { name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
};

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    match: App.Data.Front.MatchData;
    games: GameDetail[];
    gameLogs: Record<number, Array<{ timestamp: string; message: string }>>;
    archetypes: App.Data.Front.ArchetypeData[];
    imported: boolean;
}>();

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);
const editingNotes = ref(false);
const notesTextarea = ref<HTMLTextAreaElement | null>(null);

const notesForm = useForm<{ notes: string | null }>({
    notes: props.match.notes ?? null,
});

function startEditingNotes() {
    notesForm.notes = props.match.notes ?? '';
    editingNotes.value = true;
    nextTick(() => notesTextarea.value?.focus());
}

function saveNotes() {
    editingNotes.value = false;
    notesForm.submit(UpdateNotesController({ id: props.match.id }), {
        preserveScroll: true,
    });
}

const isWin = computed(() => props.match.gamesWon > props.match.gamesLost);
const opponentArchetype = computed(() => {
    const archetypes = props.match.opponentArchetypes as App.Data.Front.MatchArchetypeData[] | null;
    return archetypes?.[0] ?? null;
});
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes" />

    <div class="flex flex-col gap-4 p-3 lg:p-4">
            <!-- Imported match banner -->
            <Card v-if="imported" class="border-yellow-500/30 bg-yellow-500/5 py-0">
                <CardContent class="flex items-center gap-2 p-3 text-sm text-yellow-600 dark:text-yellow-400">
                    <AlertTriangle class="size-4 shrink-0" />
                    This is an imported match. Opening hands, sideboard changes, and turn estimates are not available.
                </CardContent>
            </Card>

            <!-- Match header -->
            <div class="flex flex-col gap-1">
                <!-- Result + opponent -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <ResultBadge :won="isWin" :showText="true" />
                        <span class="text-sm font-semibold tabular-nums">
                            {{ match.gamesWon }}-{{ match.gamesLost }}
                        </span>
                    </div>
                    <div class="h-4 w-px bg-border" />
                    <h1 class="text-sm font-semibold">vs {{ match.opponentName ?? 'Unknown' }}</h1>
                </div>

                <!-- Archetype -->
                <div class="flex items-center gap-1.5">
                    <template v-if="opponentArchetype?.archetype">
                        <span class="text-sm">{{ opponentArchetype.archetype.name }}</span>
                        <ManaSymbols :symbols="opponentArchetype.archetype.colorIdentity" />
                    </template>
                    <span v-else class="text-sm text-muted-foreground">Unknown archetype</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-5 w-5 text-muted-foreground"
                        @click="archetypeDialog?.openForMatch(match.id, match.format)"
                    >
                        <PencilIcon :size="11" />
                    </Button>
                </div>

                <!-- Metadata line -->
                <p class="text-sm text-muted-foreground">
                    {{ match.format }}
                    · {{ match.startedAtFormatted }}
                    · {{ match.matchTime }}
                    <template v-if="match.leagueName"> · {{ match.leagueName }}</template>
                </p>
            </div>

            <!-- Notes -->
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <NotepadText :size="14" />
                    <span>Notes</span>
                </div>
                <textarea
                    v-if="editingNotes"
                    ref="notesTextarea"
                    v-model="notesForm.notes"
                    class="min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    placeholder="Add your notes about this match..."
                    @blur="saveNotes"
                />
                <p
                    v-else
                    class="cursor-pointer whitespace-pre-wrap rounded-md px-3 py-2 text-sm hover:bg-accent/50"
                    @click="startEditingNotes"
                >
                    {{ match.notes || 'Click to add notes...' }}
                </p>
            </div>

            <!-- Per-game sections -->
            <div class="flex flex-col gap-3">
                <MatchGame
                    v-for="game in games"
                    :key="game.id"
                    :game="game"
                    :game-log="gameLogs[game.id] ?? []"
                    :opponent-name="(match.opponentName as string) ?? 'Opponent'"
                />
            </div>
    </div>
</template>
