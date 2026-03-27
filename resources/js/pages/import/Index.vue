<script setup lang="ts">
import ScanController from '@/actions/App/Http/Controllers/Import/ScanController';
import StoreController from '@/actions/App/Http/Controllers/Import/StoreController';
import DestroyController from '@/actions/App/Http/Controllers/Import/DestroyController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { AlertTriangle, Check, Eye, FileUp, Minus, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function jsonHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
    };
}

interface DeckVersionOption {
    id: number;
    deck_name: string;
    deck_deleted: boolean;
    modified_at: string;
    format: string;
}

interface ImportableMatch {
    history_id: number;
    started_at: string;
    opponent: string;
    format: string;
    format_raw: string;
    games_won: number;
    games_lost: number;
    outcome: string;
    round: number;
    description: string;
    has_game_log: boolean;
    game_log_token: string | null;
    games: Array<{
        game_index: number;
        won: boolean;
        on_play: boolean;
        starting_hand_size: number;
        opponent_hand_size: number;
        started_at: string;
        ended_at: string;
        local_cards: Array<{ mtgo_id: number; name: string }>;
        opponent_cards: Array<{ mtgo_id: number; name: string }>;
    }> | null;
    local_player: string | null;
    local_cards: Array<{ mtgo_id: number; name: string }> | null;
    opponent_cards: Array<{ mtgo_id: number; name: string }> | null;
    suggested_deck_version_id: number | null;
    suggested_deck_name: string | null;
    deck_match_confidence: number | null;
    deck_deleted: boolean;
    game_ids: number[];
}

const props = defineProps<{
    deckVersions: DeckVersionOption[];
    importedCount: number;
}>();

const scanning = ref(false);
const importing = ref(false);
const scanned = ref(false);
const matches = ref<ImportableMatch[]>([]);
const selectedIds = ref<Set<number>>(new Set());
const deckChoices = ref<Record<number, number | null>>({});
const errorMessage = ref<string | null>(null);
const importResult = ref<{ imported: number; skipped: number } | null>(null);
const importedCount = ref(props.importedCount);

const formatFilter = ref('all');

const availableFormats = computed(() => {
    const formats = [...new Set(matches.value.map((m) => m.format))].sort();
    return formats;
});

const filteredMatches = computed(() => {
    if (formatFilter.value === 'all') return matches.value;
    return matches.value.filter((m) => m.format === formatFilter.value);
});

const summary = computed(() => {
    const total = matches.value.length;
    const withGameLog = matches.value.filter((m) => m.has_game_log).length;
    const withDeck = matches.value.filter((m) => m.suggested_deck_version_id !== null).length;
    return { total, withGameLog, withDeck };
});

const selectedCount = computed(() => selectedIds.value.size);

const activeDeckVersions = computed(() => props.deckVersions.filter((d) => !d.deck_deleted));
const deletedDeckVersions = computed(() => props.deckVersions.filter((d) => d.deck_deleted));

const selectedWithoutDeck = computed(() => {
    return [...selectedIds.value].filter((id) => !deckChoices.value[id]);
});

const canImport = computed(() => {
    return selectedCount.value > 0 && selectedWithoutDeck.value.length === 0 && !importing.value;
});

async function scan() {
    scanning.value = true;
    scanned.value = false;
    importResult.value = null;
    errorMessage.value = null;

    try {
        const response = await fetch(ScanController.url(), {
            method: 'POST',
            headers: jsonHeaders(),
        });

        if (!response.ok) {
            const text = await response.text();
            errorMessage.value = `Scan failed (${response.status}): ${text || response.statusText}`;
            return;
        }

        const data = await response.json();
        matches.value = data.matches;
        scanned.value = true;

        for (const match of matches.value) {
            if (match.suggested_deck_version_id && (match.deck_match_confidence ?? 0) >= 0.6) {
                deckChoices.value[match.history_id] = match.suggested_deck_version_id;
            } else {
                deckChoices.value[match.history_id] = null;
            }
        }
    } catch (e) {
        errorMessage.value = `Scan failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    } finally {
        scanning.value = false;
    }
}

function toggleSelect(historyId: number) {
    if (selectedIds.value.has(historyId)) {
        selectedIds.value.delete(historyId);
    } else {
        selectedIds.value.add(historyId);
    }
    selectedIds.value = new Set(selectedIds.value);
}

function selectAll() {
    selectedIds.value = new Set(filteredMatches.value.map((m) => m.history_id));
}

function selectWithDeck() {
    selectedIds.value = new Set(
        filteredMatches.value.filter((m) => deckChoices.value[m.history_id] !== null).map((m) => m.history_id),
    );
}

function deselectAll() {
    selectedIds.value = new Set();
}

const bulkDeckId = ref<number | null>(null);

function assignDeckToSelected() {
    for (const id of selectedIds.value) {
        deckChoices.value[id] = bulkDeckId.value;
    }
}

async function importSelected() {
    if (!canImport.value) return;

    importing.value = true;
    errorMessage.value = null;

    const selected = matches.value
        .filter((m) => selectedIds.value.has(m.history_id))
        .map((m) => ({
            ...m,
            deck_version_id: deckChoices.value[m.history_id] ?? null,
        }));

    try {
        const response = await fetch(StoreController.url(), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ matches: selected }),
        });

        if (!response.ok) {
            const text = await response.text();
            errorMessage.value = `Import failed (${response.status}): ${text || response.statusText}`;
            return;
        }

        const data = await response.json();
        importResult.value = data;
        importedCount.value += data.imported;

        const importedIds = new Set(selected.map((m) => m.history_id));
        matches.value = matches.value.filter((m) => !importedIds.has(m.history_id));
        selectedIds.value = new Set();

        await nextTick();
        importResultCard.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (e) {
        errorMessage.value = `Import failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    } finally {
        importing.value = false;
    }
}

function formatDate(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function confidenceColor(confidence: number | null): string {
    if (confidence === null) return 'text-muted-foreground';
    if (confidence >= 0.8) return 'text-green-500';
    if (confidence >= 0.6) return 'text-yellow-500';
    return 'text-red-500';
}

const importResultCard = ref<InstanceType<typeof Card> | null>(null);

const cardsModalMatch = ref<ImportableMatch | null>(null);
const cardsModalOpen = ref(false);

function showCards(match: ImportableMatch) {
    cardsModalMatch.value = match;
    cardsModalOpen.value = true;
}

const deleteModalOpen = ref(false);
const deleting = ref(false);

async function deleteAllImported() {
    deleting.value = true;
    errorMessage.value = null;

    try {
        const response = await fetch(DestroyController.url(), {
            method: 'DELETE',
            headers: jsonHeaders(),
        });

        if (!response.ok) {
            const text = await response.text();
            errorMessage.value = `Delete failed (${response.status}): ${text || response.statusText}`;
            return;
        }

        const data = await response.json();
        importedCount.value = 0;
        deleteModalOpen.value = false;

        // Re-scan to refresh the list since deleted matches are now importable again
        if (scanned.value) {
            await scan();
        }
    } catch (e) {
        errorMessage.value = `Delete failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Import Match History</h1>
            <p class="text-sm text-muted-foreground">
                Backfill matches from MTGO's local history files into your database.
            </p>
        </div>

        <!-- Warning banner -->
        <Card class="border-yellow-500/50 bg-yellow-500/5">
            <CardContent class="flex items-start gap-3 p-4">
                <AlertTriangle class="mt-0.5 size-5 shrink-0 text-yellow-500" />
                <p class="text-sm">
                    <strong>Imported matches have reduced data fidelity.</strong> Opening hands, sideboard changes,
                    game timelines, and turn estimates will not be available. Card game statistics will be
                    approximate — cards are counted as "seen" based on game log mentions, not zone tracking.
                </p>
            </CardContent>
        </Card>

        <!-- Imported count + delete -->
        <div v-if="importedCount > 0" class="flex items-center justify-between rounded-md border border-border px-4 py-3">
            <p class="text-sm text-muted-foreground">
                <strong>{{ importedCount }}</strong> previously imported match(es) in the database.
            </p>
            <Button variant="outline" size="sm" class="text-red-500 hover:text-red-600" @click="deleteModalOpen = true">
                <Trash2 class="mr-1.5 size-3.5" />
                Delete all imported
            </Button>
        </div>

        <!-- Scan button -->
        <div v-if="!scanned" class="flex justify-center">
            <Button @click="scan" :disabled="scanning" size="lg">
                <Spinner v-if="scanning" class="mr-2 size-4" />
                <FileUp v-else class="mr-2 size-4" />
                {{ scanning ? 'Scanning...' : 'Scan Match History' }}
            </Button>
        </div>

        <!-- Error banner -->
        <Card v-if="errorMessage" class="border-red-500/50 bg-red-500/5">
            <CardContent class="flex items-center gap-3 p-4">
                <AlertTriangle class="size-5 shrink-0 text-red-500" />
                <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
            </CardContent>
        </Card>

        <!-- Import result banner -->
        <Card v-if="importResult" ref="importResultCard" class="border-green-500/50 bg-green-500/5">
            <CardContent class="flex items-center gap-3 p-4">
                <Check class="size-5 text-green-500" />
                <p class="text-sm">
                    Successfully imported {{ importResult.imported }} match(es).
                    <span v-if="importResult.skipped"> {{ importResult.skipped }} skipped (already exist).</span>
                </p>
            </CardContent>
        </Card>

        <!-- Results -->
        <template v-if="scanned && matches.length > 0">
            <!-- Summary + filters -->
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-muted-foreground">
                        Found <strong>{{ summary.total }}</strong> matches.
                        <strong>{{ summary.withGameLog }}</strong> have game logs.
                        <strong>{{ summary.withDeck }}</strong> have suggested deck matches.
                    </p>
                    <div class="flex items-center gap-2">
                        <select
                            v-model="formatFilter"
                            class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                        >
                            <option value="all">All formats</option>
                            <option v-for="fmt in availableFormats" :key="fmt" :value="fmt">{{ fmt }}</option>
                        </select>
                        <Button variant="outline" size="sm" @click="selectAll">Select all</Button>
                        <Button variant="outline" size="sm" @click="selectWithDeck">Select with deck</Button>
                        <Button variant="outline" size="sm" @click="deselectAll">Deselect all</Button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-md border overflow-hidden">
                <table class="w-full table-fixed text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50 text-left text-xs text-muted-foreground">
                            <th class="w-10 p-3"></th>
                            <th class="w-[100px] p-3">Date</th>
                            <th class="p-3">Opponent</th>
                            <th class="w-[90px] p-3">Format</th>
                            <th class="w-[100px] p-3">Result</th>
                            <th class="w-[200px] p-3">Deck</th>
                            <th class="w-20 p-3 text-center">Confidence</th>
                            <th class="w-10 p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="match in filteredMatches" :key="match.history_id">
                            <tr
                                class="transition-colors hover:bg-muted/30"
                                :class="[
                                    selectedIds.has(match.history_id) ? 'bg-muted/10' : '',
                                    match.local_cards && match.local_cards.length > 0 ? '' : 'border-b',
                                ]"
                            >
                                <td class="p-3">
                                    <input
                                        type="checkbox"
                                        :checked="selectedIds.has(match.history_id)"
                                        @change="toggleSelect(match.history_id)"
                                        class="size-4 rounded border-input accent-primary"
                                    />
                                </td>
                                <td class="p-3 whitespace-nowrap">{{ formatDate(match.started_at) }}</td>
                                <td class="p-3">{{ match.opponent }}</td>
                                <td class="p-3">{{ match.format }}</td>
                                <td class="p-3">
                                    <template v-if="match.has_game_log && match.games">
                                        <span class="flex gap-1">
                                            <Badge
                                                v-for="(game, i) in match.games"
                                                :key="i"
                                                :variant="game.won ? 'default' : 'destructive'"
                                                class="text-xs"
                                            >
                                                {{ game.won ? 'W' : 'L' }}
                                            </Badge>
                                        </span>
                                    </template>
                                    <template v-else>
                                        <span class="text-muted-foreground">
                                            {{ match.games_won }}-{{ match.games_lost }}
                                        </span>
                                    </template>
                                </td>
                                <td class="p-3">
                                    <select
                                        class="h-8 w-full max-w-[200px] rounded-md border border-input bg-background px-2 text-xs"
                                        :value="deckChoices[match.history_id] ?? ''"
                                        @change="deckChoices[match.history_id] = ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null"
                                    >
                                        <option value="">No deck</option>
                                        <optgroup v-if="activeDeckVersions.length" label="Active Decks">
                                            <option
                                                v-for="dv in activeDeckVersions"
                                                :key="dv.id"
                                                :value="dv.id"
                                            >
                                                {{ dv.deck_name }} ({{ dv.modified_at }})
                                            </option>
                                        </optgroup>
                                        <optgroup v-if="deletedDeckVersions.length" label="Deleted Decks">
                                            <option
                                                v-for="dv in deletedDeckVersions"
                                                :key="dv.id"
                                                :value="dv.id"
                                            >
                                                {{ dv.deck_name }} (deleted) ({{ dv.modified_at }})
                                            </option>
                                        </optgroup>
                                    </select>
                                </td>
                                <td class="p-3 text-center">
                                    <span
                                        v-if="match.deck_match_confidence !== null"
                                        :class="confidenceColor(match.deck_match_confidence)"
                                        class="text-xs font-medium"
                                    >
                                        {{ Math.round(match.deck_match_confidence * 100) }}%
                                    </span>
                                    <Minus v-else class="mx-auto size-4 text-muted-foreground" />
                                </td>
                                <td class="p-3 text-center">
                                    <button
                                        v-if="match.local_cards && match.local_cards.length > 0"
                                        @click="showCards(match)"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        <Eye class="size-3.5" />
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-if="match.local_cards && match.local_cards.length > 0"
                                class="border-b"
                                :class="{ 'bg-muted/10': selectedIds.has(match.history_id) }"
                            >
                                <td colspan="8" class="px-3 pb-2 pt-0">
                                    <div class="flex items-center gap-1 overflow-x-auto scrollbar-none">
                                        <Badge
                                            v-for="card in match.local_cards"
                                            :key="card.mtgo_id"
                                            variant="secondary"
                                            class="shrink-0 text-[11px] leading-tight px-1.5 py-0"
                                        >
                                            {{ card.name }}
                                        </Badge>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Bottom spacer so table content isn't hidden behind fixed bar -->
            <div class="h-20"></div>
        </template>

        <!-- No results -->
        <Card v-if="scanned && matches.length === 0 && !importResult">
            <CardContent class="p-4 text-center text-sm text-muted-foreground">
                No importable matches found. All history records are already in your database.
            </CardContent>
        </Card>

        <!-- Fixed bottom bar -->
        <div
            v-if="scanned && matches.length > 0 && selectedCount > 0"
            class="fixed inset-x-0 bottom-0 z-10 border-t border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3">
                <div class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">{{ selectedCount }} selected</span>
                    <div class="flex items-center gap-2">
                        <select
                            class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                            :value="bulkDeckId ?? ''"
                            @change="bulkDeckId = ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null"
                        >
                            <option value="">Assign deck...</option>
                            <optgroup v-if="activeDeckVersions.length" label="Active Decks">
                                <option v-for="dv in activeDeckVersions" :key="dv.id" :value="dv.id">
                                    {{ dv.deck_name }} ({{ dv.modified_at }})
                                </option>
                            </optgroup>
                            <optgroup v-if="deletedDeckVersions.length" label="Deleted Decks">
                                <option v-for="dv in deletedDeckVersions" :key="dv.id" :value="dv.id">
                                    {{ dv.deck_name }} (deleted) ({{ dv.modified_at }})
                                </option>
                            </optgroup>
                        </select>
                        <Button variant="outline" size="sm" @click="assignDeckToSelected" :disabled="!bulkDeckId">
                            Apply
                        </Button>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="selectedWithoutDeck.length > 0" class="text-xs text-red-500">
                        {{ selectedWithoutDeck.length }} match(es) missing a deck
                    </span>
                    <Button @click="importSelected" :disabled="!canImport">
                        <Spinner v-if="importing" class="mr-2 size-4" />
                        {{ importing ? 'Importing...' : `Import ${selectedCount} match(es)` }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Cards modal -->
        <Dialog v-model:open="cardsModalOpen">
            <DialogContent class="max-h-[80vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        Cards seen — {{ cardsModalMatch?.opponent }}
                        <span class="text-muted-foreground font-normal">
                            ({{ cardsModalMatch?.format }}, {{ cardsModalMatch ? formatDate(cardsModalMatch.started_at) : '' }})
                        </span>
                    </DialogTitle>
                    <DialogDescription>
                        Cards played or revealed by {{ cardsModalMatch?.local_player ?? 'you' }} during this match.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="cardsModalMatch?.local_cards" class="space-y-4">
                    <div>
                        <h4 class="mb-2 text-xs font-medium text-muted-foreground uppercase">Your cards ({{ cardsModalMatch.local_cards.length }})</h4>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="card in cardsModalMatch.local_cards"
                                :key="card.mtgo_id"
                                variant="secondary"
                                class="text-xs"
                            >
                                {{ card.name }}
                            </Badge>
                        </div>
                    </div>
                    <div v-if="cardsModalMatch.opponent_cards && cardsModalMatch.opponent_cards.length > 0">
                        <h4 class="mb-2 text-xs font-medium text-muted-foreground uppercase">Opponent cards ({{ cardsModalMatch.opponent_cards.length }})</h4>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="card in cardsModalMatch.opponent_cards"
                                :key="card.mtgo_id"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ card.name }}
                            </Badge>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Delete confirmation modal -->
        <Dialog v-model:open="deleteModalOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete all imported matches?</DialogTitle>
                    <DialogDescription>
                        This will permanently delete <strong>{{ importedCount }}</strong> imported match(es) and all
                        associated data (games, card stats, archetypes). This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2 sm:gap-0">
                    <Button variant="outline" @click="deleteModalOpen = false" :disabled="deleting">Cancel</Button>
                    <Button variant="destructive" @click="deleteAllImported" :disabled="deleting">
                        <Spinner v-if="deleting" class="mr-2 size-4" />
                        {{ deleting ? 'Deleting...' : `Delete ${importedCount} match(es)` }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
