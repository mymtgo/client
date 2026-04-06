<script setup lang="ts">
import ScanController from '@/actions/App/Http/Controllers/Import/ScanController';
import ScanStatusController from '@/actions/App/Http/Controllers/Import/ScanStatusController';
import ScanMatchesController from '@/actions/App/Http/Controllers/Import/ScanMatchesController';
import ScanMatchCardsController from '@/actions/App/Http/Controllers/Import/ScanMatchCardsController';
import StoreController from '@/actions/App/Http/Controllers/Import/StoreController';
import ImportAllController from '@/actions/App/Http/Controllers/Import/ImportAllController';
import CancelScanController from '@/actions/App/Http/Controllers/Import/CancelScanController';
import DestroyController from '@/actions/App/Http/Controllers/Import/DestroyController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { AlertTriangle, Check, ChevronLeft, ChevronRight, Eye, FileUp, Trash2, X } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';

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

interface ConfidenceStats {
    high: number;
    low: number;
    none: number;
}

interface ExistingScan {
    id: number;
    deck_version_id: number;
    deck_name: string;
    status: string;
    stage: string;
    progress: number;
    total: number;
    match_count: number;
    formats: string[];
    confidence_stats: ConfidenceStats | null;
}

interface ScanMatch {
    id: number;
    history_id: number;
    started_at: string;
    opponent: string;
    format: string;
    games_won: number;
    games_lost: number;
    outcome: string;
    confidence: number | null;
    game_log_token: string | null;
    round: number;
    description: string;
}

interface PaginatedResponse {
    data: ScanMatch[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

const props = defineProps<{
    deckVersions: DeckVersionOption[];
    importedCount: number;
    existingScan: ExistingScan | null;
}>();

// State
type PageState = 'setup' | 'processing' | 'results';

const state = ref<PageState>(props.existingScan?.status === 'processing' ? 'processing' : props.existingScan?.status === 'complete' ? 'results' : 'setup');
const selectedDeckId = ref<number | null>(props.existingScan?.deck_version_id ?? null);
const scanId = ref<number | null>(props.existingScan?.id ?? null);
const scanDeckName = ref<string>(props.existingScan?.deck_name ?? '');
const scanProgress = ref(props.existingScan?.progress ?? 0);
const scanTotal = ref(props.existingScan?.total ?? 0);
const scanStage = ref(props.existingScan?.stage ?? 'discovering');
const scanError = ref<string | null>(null);

const matches = ref<ScanMatch[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const totalMatches = ref(props.existingScan?.match_count ?? 0);
const selectedIds = ref<Set<number>>(new Set());

const errorMessage = ref<string | null>(null);
const importResult = ref<{ imported?: number; skipped?: number; dispatched?: number } | null>(null);
const importedCount = ref(props.importedCount);
const importing = ref(false);

const formatFilter = ref('');
const availableFormats = ref<string[]>(props.existingScan?.formats ?? []);
const confidenceStats = ref<ConfidenceStats | null>(props.existingScan?.confidence_stats ?? null);

// Confidence threshold slider — default 0.1 to exclude matches with no game log
const minConfidence = ref(0.1);

// Sort
const sortBy = ref('confidence');
const sortOptions = [
    { value: 'confidence', label: 'Confidence (high first)' },
    { value: 'started_at', label: 'Date (newest first)' },
    { value: 'opponent', label: 'Opponent (A-Z)' },
];

const activeDeckVersions = computed(() => props.deckVersions.filter((d) => !d.deck_deleted));
const deletedDeckVersions = computed(() => props.deckVersions.filter((d) => d.deck_deleted));

const selectedCount = computed(() => selectedIds.value.size);

// Resolve deck name from selected ID
const selectedDeckName = computed(() => {
    if (!selectedDeckId.value) return '';
    const dv = props.deckVersions.find((d) => d.id === selectedDeckId.value);
    return dv?.deck_name ?? '';
});

// Polling
let pollTimer: ReturnType<typeof setInterval> | null = null;

function startPolling() {
    stopPolling();
    pollTimer = setInterval(pollStatus, 2000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

onUnmounted(stopPolling);

if (state.value === 'processing' && scanId.value) {
    startPolling();
}
if (state.value === 'results' && scanId.value) {
    loadMatches();
}

async function pollStatus() {
    if (!scanId.value) return;

    try {
        const response = await fetch(ScanStatusController.url(scanId.value), {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        });

        if (!response.ok) return;

        const data = await response.json();
        scanProgress.value = data.progress;
        scanTotal.value = data.total;
        scanStage.value = data.stage ?? 'discovering';

        if (data.status === 'complete') {
            stopPolling();
            totalMatches.value = data.match_count ?? 0;
            availableFormats.value = data.formats ?? [];
            confidenceStats.value = data.confidence_stats ?? null;
            state.value = 'results';
            await loadMatches();
        } else if (data.status === 'failed') {
            stopPolling();
            scanError.value = data.error ?? 'Scan failed';
            state.value = 'setup';
        } else if (data.status === 'cancelled') {
            stopPolling();
            state.value = 'setup';
        }
    } catch {
        // Silently retry
    }
}

async function startScan() {
    if (!selectedDeckId.value) return;

    errorMessage.value = null;
    importResult.value = null;
    scanError.value = null;
    scanDeckName.value = selectedDeckName.value;

    try {
        const response = await fetch(ScanController.url(), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ deck_version_id: selectedDeckId.value }),
        });

        if (!response.ok) {
            const text = await response.text();
            errorMessage.value = `Scan failed (${response.status}): ${text || response.statusText}`;
            return;
        }

        const data = await response.json();
        scanId.value = data.scan_id;
        scanProgress.value = 0;
        scanTotal.value = 0;
        state.value = 'processing';
        startPolling();
    } catch (e) {
        errorMessage.value = `Scan failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    }
}

async function cancelScanAndReset() {
    if (scanId.value) {
        try {
            await fetch(CancelScanController.url(scanId.value), {
                method: 'DELETE',
                headers: jsonHeaders(),
            });
        } catch {
            // Best effort
        }
    }

    stopPolling();
    stopImportPolling();
    state.value = 'setup';
    scanId.value = null;
    matches.value = [];
    selectedIds.value = new Set();
    totalMatches.value = 0;
    confidenceStats.value = null;
    availableFormats.value = [];
    formatFilter.value = '';
    minConfidence.value = 0;
}

async function loadMatches(page = 1) {
    if (!scanId.value) return;

    try {
        const query: Record<string, string> = { page: String(page), sort: sortBy.value, dir: 'desc' };
        if (sortBy.value === 'opponent') query.dir = 'asc';
        if (formatFilter.value) query.format = formatFilter.value;
        if (minConfidence.value > 0) query.min_confidence = String(minConfidence.value);

        const response = await fetch(
            ScanMatchesController.url(scanId.value, { query }),
            {
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            },
        );

        if (!response.ok) return;

        const data: PaginatedResponse = await response.json();
        matches.value = data.data;
        currentPage.value = data.current_page;
        lastPage.value = data.last_page;
        totalMatches.value = data.total;

        if (data.total === 0) {
            stopImportPolling();
        }
    } catch (e) {
        errorMessage.value = `Failed to load matches: ${e instanceof Error ? e.message : 'Unknown error'}`;
    }
}

// Debounced watchers for filters
let filterTimeout: ReturnType<typeof setTimeout> | null = null;

watch([formatFilter, minConfidence, sortBy], () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        currentPage.value = 1;
        loadMatches(1);
    }, 300);
});

// Selection
function toggleSelect(historyId: number) {
    const newSet = new Set(selectedIds.value);
    if (newSet.has(historyId)) {
        newSet.delete(historyId);
    } else {
        newSet.add(historyId);
    }
    selectedIds.value = newSet;
}

const allPageSelected = computed(() =>
    matches.value.length > 0 && matches.value.every((m) => selectedIds.value.has(m.history_id)),
);

function togglePageSelect() {
    const newSet = new Set(selectedIds.value);
    if (allPageSelected.value) {
        for (const m of matches.value) {
            newSet.delete(m.history_id);
        }
    } else {
        for (const m of matches.value) {
            newSet.add(m.history_id);
        }
    }
    selectedIds.value = newSet;
}

// Import polling — reload match list until queue finishes processing
let importPollTimer: ReturnType<typeof setInterval> | null = null;

function startImportPolling() {
    stopImportPolling();
    importPollTimer = setInterval(() => loadMatches(currentPage.value), 3000);
}

function stopImportPolling() {
    if (importPollTimer) {
        clearInterval(importPollTimer);
        importPollTimer = null;
    }
}

onUnmounted(stopImportPolling);

// Import
async function importSelected() {
    if (selectedIds.value.size === 0 || !scanId.value) return;
    importing.value = true;
    errorMessage.value = null;

    try {
        const response = await fetch(StoreController.url(scanId.value), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ history_ids: [...selectedIds.value] }),
        });

        if (!response.ok) {
            const text = await response.text();
            errorMessage.value = `Import failed (${response.status}): ${text || response.statusText}`;
            return;
        }

        const data = await response.json();
        importResult.value = data;
        selectedIds.value = new Set();
        startImportPolling();
    } catch (e) {
        errorMessage.value = `Import failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    } finally {
        importing.value = false;
    }
}

const importAllConfirmOpen = ref(false);

async function importAll() {
    if (!scanId.value) return;
    importing.value = true;
    importAllConfirmOpen.value = false;
    errorMessage.value = null;

    try {
        const query: Record<string, string> = {};
        if (formatFilter.value) query.format = formatFilter.value;
        if (minConfidence.value > 0) query.min_confidence = String(minConfidence.value);

        const response = await fetch(ImportAllController.url(scanId.value, { query }), {
            method: 'POST',
            headers: jsonHeaders(),
        });

        if (!response.ok) {
            const text = await response.text();
            errorMessage.value = `Import failed (${response.status}): ${text || response.statusText}`;
            return;
        }

        const data = await response.json();
        importResult.value = data;
        selectedIds.value = new Set();
        startImportPolling();
    } catch (e) {
        errorMessage.value = `Import failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    } finally {
        importing.value = false;
    }
}

// Cards modal
const cardsModalOpen = ref(false);
const cardsModalMatch = ref<ScanMatch | null>(null);
const cardsModalLoading = ref(false);
const cardsModalData = ref<{ local_cards: Array<{ mtgo_id: number; name: string }>; opponent_cards: Array<{ mtgo_id: number; name: string }> } | null>(null);

async function showCards(match: ScanMatch) {
    cardsModalMatch.value = match;
    cardsModalOpen.value = true;
    cardsModalLoading.value = true;
    cardsModalData.value = null;

    try {
        const response = await fetch(ScanMatchCardsController.url(match.id), {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        });

        if (response.ok) {
            cardsModalData.value = await response.json();
        }
    } catch {
        // Silently fail
    } finally {
        cardsModalLoading.value = false;
    }
}

// Delete all imported
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

        importedCount.value = 0;
        deleteModalOpen.value = false;
    } catch (e) {
        errorMessage.value = `Delete failed: ${e instanceof Error ? e.message : 'Unknown error'}`;
    } finally {
        deleting.value = false;
    }
}

// Helpers
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

function confidenceText(confidence: number | null): string {
    if (confidence === null) return '-';
    return Math.round(confidence * 100) + '%';
}

const confidenceLabel = computed(() => {
    if (minConfidence.value === 0) return 'Off';
    if (minConfidence.value <= 0.1) return 'Any';
    return Math.round(minConfidence.value * 100) + '%+';
});

const progressPercent = computed(() => {
    if (scanTotal.value === 0) return 0;
    return Math.round((scanProgress.value / scanTotal.value) * 100);
});

const stageLabels: Record<string, string> = {
    discovering: 'Discovering game logs...',
    decoding: 'Decoding game logs...',
    parsing: 'Parsing match history...',
    scoring: 'Scoring matches...',
};

const stageLabel = computed(() => stageLabels[scanStage.value] ?? 'Processing...');
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
        <Card class="border-yellow-500/50 bg-yellow-500/5 py-0">
            <CardContent class="flex items-start gap-3 p-3">
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

        <!-- Error banner -->
        <Card v-if="errorMessage || scanError" class="border-red-500/50 bg-red-500/5">
            <CardContent class="flex items-center gap-3 p-4">
                <AlertTriangle class="size-5 shrink-0 text-red-500" />
                <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage || scanError }}</p>
            </CardContent>
        </Card>

        <!-- Import result banner -->
        <Card v-if="importResult" class="border-green-500/50 bg-green-500/5">
            <CardContent class="flex items-center gap-3 p-4">
                <Check class="size-5 text-green-500" />
                <p class="text-sm">
                    {{ importResult.dispatched }} match(es) queued for import. They will appear in your match history as they are processed.
                </p>
            </CardContent>
        </Card>

        <!-- STATE 1: Setup -->
        <template v-if="state === 'setup'">
            <Card>
                <CardContent class="space-y-4 p-6">
                    <div>
                        <label class="mb-2 block text-sm font-medium">Select a deck</label>
                        <select
                            v-model.number="selectedDeckId"
                            class="h-10 w-full max-w-md rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option :value="null" disabled>Choose a deck version...</option>
                            <optgroup v-if="activeDeckVersions.length" label="Active Decks">
                                <option v-for="dv in activeDeckVersions" :key="dv.id" :value="dv.id">
                                    {{ dv.deck_name }} ({{ dv.format }}, {{ dv.modified_at }})
                                </option>
                            </optgroup>
                            <optgroup v-if="deletedDeckVersions.length" label="Deleted Decks">
                                <option v-for="dv in deletedDeckVersions" :key="dv.id" :value="dv.id">
                                    {{ dv.deck_name }} (deleted) ({{ dv.modified_at }})
                                </option>
                            </optgroup>
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            All imported matches will be assigned to this deck. Confidence scoring compares
                            cards seen in game logs against the deck's card list.
                        </p>
                    </div>

                    <Button @click="startScan" :disabled="!selectedDeckId" size="lg">
                        <FileUp class="mr-2 size-4" />
                        Load Matches
                    </Button>
                </CardContent>
            </Card>
        </template>

        <!-- STATE 2: Processing -->
        <template v-if="state === 'processing'">
            <Card>
                <CardContent class="space-y-4 p-6 text-center">
                    <Spinner class="mx-auto size-8" />
                    <div>
                        <p class="text-sm font-medium">{{ stageLabel }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            This may take a moment for large histories.
                        </p>
                    </div>

                    <div v-if="scanTotal > 0" class="mx-auto max-w-sm space-y-1">
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary transition-all duration-300"
                                :style="{ width: progressPercent + '%' }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ scanProgress }} / {{ scanTotal }} ({{ progressPercent }}%)
                        </p>
                    </div>

                    <Button variant="outline" size="sm" @click="cancelScanAndReset">
                        Cancel
                    </Button>
                </CardContent>
            </Card>
        </template>

        <!-- STATE 3: Results -->
        <template v-if="state === 'results'">
            <!-- Deck header -->
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold">{{ scanDeckName }}</h2>
                    <div class="mt-1 flex items-center gap-4 text-sm text-muted-foreground">
                        <span><strong>{{ totalMatches }}</strong> matches</span>
                        <template v-if="confidenceStats">
                            <span class="text-green-500">{{ confidenceStats.high }} high</span>
                            <span class="text-red-500">{{ confidenceStats.low }} low</span>
                            <span v-if="confidenceStats.none > 0">{{ confidenceStats.none }} no log</span>
                        </template>
                    </div>
                </div>
                <Button variant="outline" size="sm" @click="cancelScanAndReset">
                    <X class="mr-1.5 size-3.5" />
                    Start over
                </Button>
            </div>

            <!-- Filter controls -->
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <label class="text-xs text-muted-foreground">Format</label>
                    <select
                        v-model="formatFilter"
                        class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                    >
                        <option value="">All formats</option>
                        <option v-for="fmt in availableFormats" :key="fmt" :value="fmt">{{ fmt }}</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-xs text-muted-foreground">Sort by</label>
                    <select
                        v-model="sortBy"
                        class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                    >
                        <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-xs text-muted-foreground">Min. confidence</label>
                    <input
                        v-model.number="minConfidence"
                        type="range"
                        min="0"
                        max="1"
                        step="0.1"
                        class="h-1.5 w-24 cursor-pointer accent-primary"
                    />
                    <span class="w-10 text-xs text-muted-foreground">{{ confidenceLabel }}</span>
                </div>
            </div>

            <!-- Match table -->
            <Card class="overflow-hidden">
                <CardContent class="p-0">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/30 text-left text-xs font-medium text-muted-foreground">
                                <th class="w-10 p-3">
                                    <input
                                        type="checkbox"
                                        :checked="allPageSelected"
                                        @change="togglePageSelect"
                                        class="size-4 rounded border-input accent-primary"
                                    />
                                </th>
                                <th class="p-3">Date</th>
                                <th class="p-3">Opponent</th>
                                <th class="p-3">Format</th>
                                <th class="p-3">Result</th>
                                <th class="p-3 text-right">Confidence</th>
                                <th class="w-10 p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="match in matches"
                                :key="match.history_id"
                                class="border-b transition-colors last:border-b-0 hover:bg-muted/30"
                                :class="{ 'bg-muted/10': selectedIds.has(match.history_id) }"
                            >
                                <td class="w-10 p-3">
                                    <input
                                        type="checkbox"
                                        :checked="selectedIds.has(match.history_id)"
                                        @change="toggleSelect(match.history_id)"
                                        class="size-4 rounded border-input accent-primary"
                                    />
                                </td>
                                <td class="p-3 whitespace-nowrap text-muted-foreground">{{ formatDate(match.started_at) }}</td>
                                <td class="p-3">{{ match.opponent }}</td>
                                <td class="p-3">
                                    <Badge variant="outline" class="text-xs">{{ match.format }}</Badge>
                                </td>
                                <td class="p-3">
                                    <Badge :variant="match.outcome === 'win' ? 'default' : 'destructive'" class="text-xs">
                                        {{ match.games_won }}-{{ match.games_lost }}
                                    </Badge>
                                </td>
                                <td class="p-3 text-right">
                                    <span
                                        :class="confidenceColor(match.confidence)"
                                        class="text-xs font-medium"
                                    >
                                        {{ confidenceText(match.confidence) }}
                                    </span>
                                </td>
                                <td class="w-10 p-3 text-center">
                                    <button
                                        v-if="match.game_log_token"
                                        @click="showCards(match)"
                                        class="inline-flex items-center rounded px-1.5 py-0.5 text-muted-foreground hover:text-foreground"
                                    >
                                        <Eye class="size-3.5" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="matches.length === 0">
                                <td colspan="7" class="p-8 text-center text-sm text-muted-foreground">
                                    No matches found{{ formatFilter ? ' for this format' : '' }}{{ minConfidence > 0 ? ' at this confidence level' : '' }}.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Pagination -->
            <div v-if="lastPage > 1" class="flex items-center justify-between">
                <p class="text-xs text-muted-foreground">
                    Page {{ currentPage }} of {{ lastPage }}
                </p>
                <div class="flex items-center gap-1">
                    <Button variant="outline" size="sm" :disabled="currentPage <= 1" @click="loadMatches(currentPage - 1)">
                        <ChevronLeft class="size-4" />
                    </Button>
                    <Button variant="outline" size="sm" :disabled="currentPage >= lastPage" @click="loadMatches(currentPage + 1)">
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>

            <!-- Bottom spacer -->
            <div class="h-20"></div>
        </template>

        <!-- Sticky bottom bar -->
        <div
            v-if="state === 'results' && (selectedCount > 0 || totalMatches > 0)"
            class="fixed inset-x-0 bottom-0 z-10 border-t border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3">
                <span class="text-sm text-muted-foreground">
                    <template v-if="selectedCount > 0">{{ selectedCount }} selected</template>
                    <template v-else>{{ totalMatches }} total matches</template>
                </span>
                <div class="flex items-center gap-3">
                    <Button
                        v-if="selectedCount > 0"
                        @click="importSelected"
                        :disabled="importing"
                    >
                        <Spinner v-if="importing" class="mr-2 size-4" />
                        {{ importing ? 'Importing...' : `Import ${selectedCount} match(es)` }}
                    </Button>
                    <Button
                        variant="outline"
                        @click="importAllConfirmOpen = true"
                        :disabled="importing || totalMatches === 0"
                    >
                        Import All {{ totalMatches }} Matches
                    </Button>
                </div>
            </div>
        </div>

        <!-- Import all confirmation -->
        <Dialog v-model:open="importAllConfirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Import all matches?</DialogTitle>
                    <DialogDescription>
                        This will import all <strong>{{ totalMatches }}</strong> matches from the scan
                        and assign them to <strong>{{ scanDeckName }}</strong>.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2 sm:gap-0">
                    <Button variant="outline" @click="importAllConfirmOpen = false">Cancel</Button>
                    <Button @click="importAll">
                        Import {{ totalMatches }} matches
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Cards modal -->
        <Dialog v-model:open="cardsModalOpen">
            <DialogContent class="max-h-[80vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        Cards seen — {{ cardsModalMatch?.opponent }}
                        <span class="font-normal text-muted-foreground">
                            ({{ cardsModalMatch?.format }}, {{ cardsModalMatch ? formatDate(cardsModalMatch.started_at) : '' }})
                        </span>
                    </DialogTitle>
                    <DialogDescription>
                        Cards played or revealed during this match.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="cardsModalLoading" class="flex justify-center py-8">
                    <Spinner class="size-6" />
                </div>

                <div v-else-if="cardsModalData" class="space-y-4">
                    <div v-if="cardsModalData.local_cards.length > 0">
                        <h4 class="mb-2 text-xs font-medium uppercase text-muted-foreground">Your cards ({{ cardsModalData.local_cards.length }})</h4>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="card in cardsModalData.local_cards"
                                :key="card.mtgo_id"
                                variant="secondary"
                                class="text-xs"
                            >
                                {{ card.name }}
                            </Badge>
                        </div>
                    </div>
                    <div v-if="cardsModalData.opponent_cards.length > 0">
                        <h4 class="mb-2 text-xs font-medium uppercase text-muted-foreground">Opponent cards ({{ cardsModalData.opponent_cards.length }})</h4>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="card in cardsModalData.opponent_cards"
                                :key="card.mtgo_id"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ card.name }}
                            </Badge>
                        </div>
                    </div>
                    <p
                        v-if="cardsModalData.local_cards.length === 0 && cardsModalData.opponent_cards.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        No card data available for this match.
                    </p>
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
