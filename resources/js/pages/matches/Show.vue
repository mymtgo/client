<script setup lang="ts">
import { computed, ref, nextTick } from 'vue';
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import MatchGame from '@/pages/matches/partials/MatchGame.vue';
import MatchHero from '@/pages/matches/partials/MatchHero.vue';
import OpponentRevealsRail from '@/pages/matches/partials/OpponentRevealsRail.vue';
import UpdateNotesController from '@/actions/App/Http/Controllers/Matches/UpdateNotesController';
import MatchesController from '@/actions/App/Http/Controllers/Decks/MatchesController';
import { AlertTriangle, ChevronLeft, NotepadText } from 'lucide-vue-next';
import { useForm, router } from '@inertiajs/vue3';
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
    opponentCardsSeen: {
        name: string;
        image: string | null;
        type: string | null;
        identity: string | null;
        quantity: number;
    }[];
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
const activeGame = ref<string>(String(props.games[0]?.number ?? 1));

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

function goBack() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        router.visit(MatchesController.url(props.deck));
    }
}

function openArchetypeDialog() {
    archetypeDialog.value?.openForMatch(props.match.id, props.match.format);
}

const opponentArchetype = computed<App.Data.Front.MatchArchetypeData | null>(() => {
    const archetypes = props.match.opponentArchetypes as App.Data.Front.MatchArchetypeData[] | null;
    return archetypes?.[0] ?? null;
});

const opponentName = computed(() => (props.match.opponentName as string | null) ?? 'Opponent');
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes" />

    <div class="flex flex-col gap-5 p-4 lg:p-6">
        <!-- Back link -->
        <button
            type="button"
            class="inline-flex items-center gap-1 self-start text-sm text-muted-foreground transition-colors hover:text-foreground"
            @click="goBack"
        >
            <ChevronLeft class="size-4" />
            Back to matches
        </button>

        <!-- Imported banner -->
        <Card v-if="imported" class="border-yellow-500/30 bg-yellow-500/5 py-0">
            <CardContent class="flex items-center gap-2 p-3 text-sm text-yellow-600 dark:text-yellow-400">
                <AlertTriangle class="size-4 shrink-0" />
                This is an imported match. Opening hands, sideboard changes, and turn estimates are not available.
            </CardContent>
        </Card>

        <!-- Hero -->
        <MatchHero
            :match="match"
            :deck="deck"
            :opponent-archetype="opponentArchetype"
            :on-edit-archetype="openArchetypeDialog"
        />

        <!-- Notes -->
        <section class="flex flex-col gap-1.5">
            <div class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                <NotepadText :size="13" />
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
                class="cursor-text rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground"
                @click="startEditingNotes"
            >
                {{ match.notes || 'Click to add notes…' }}
            </p>
        </section>

        <!-- Main grid -->
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
            <!-- Game tabs + panel -->
            <Tabs v-model="activeGame" class="flex flex-col gap-4">
                <TabsList class="h-auto w-full justify-start gap-0 rounded-none border-b bg-transparent p-0">
                    <TabsTrigger
                        v-for="game in games"
                        :key="game.id"
                        :value="String(game.number)"
                        class="relative flex h-auto flex-col items-start gap-0.5 rounded-none border-0 border-b-2 border-transparent bg-transparent px-4 py-3 text-left data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                        :class="[
                            game.won
                                ? 'data-[state=active]:border-success'
                                : 'data-[state=active]:border-destructive',
                        ]"
                    >
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-[10px] tracking-widest text-muted-foreground uppercase">
                                Game {{ game.number }}
                            </span>
                            <span
                                class="rounded px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-widest uppercase"
                                :class="game.won
                                    ? 'bg-success/15 text-success ring-1 ring-success/30'
                                    : 'bg-destructive/15 text-destructive ring-1 ring-destructive/30'"
                            >
                                {{ game.won ? 'Win' : 'Loss' }}
                            </span>
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ game.onThePlay ? 'On the play' : 'On the draw' }}
                        </span>
                    </TabsTrigger>
                </TabsList>

                <TabsContent
                    v-for="game in games"
                    :key="game.id"
                    :value="String(game.number)"
                    class="mt-0"
                >
                    <MatchGame
                        :game="game"
                        :game-log="gameLogs[game.id] ?? []"
                        :opponent-name="opponentName"
                        :imported="imported"
                    />
                </TabsContent>
            </Tabs>

            <!-- Right rail -->
            <OpponentRevealsRail
                v-if="!imported"
                class="xl:sticky xl:top-4 xl:self-start"
                :games="games"
                :opponent-name="opponentName"
            />
        </div>
    </div>
</template>
