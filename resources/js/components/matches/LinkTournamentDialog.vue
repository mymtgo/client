<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import LinkToTournamentController from '@/actions/App/Http/Controllers/Matches/LinkToTournamentController';
import CandidatesController from '@/actions/App/Http/Controllers/Tournaments/CandidatesController';

type Candidate = App.Data.Front.TournamentCandidateData;

const open = ref(false);
const matchId = ref<number | null>(null);
const currentTournamentId = ref<number | null>(null);
const candidates = ref<Candidate[]>([]);
const loading = ref(false);
const search = ref('');
const showAll = ref(false);

const selectedTournamentId = ref<number | null>(null);
const round = ref<number | null>(null);

const selectedTournament = computed(
    () => candidates.value.find((c) => c.id === selectedTournamentId.value) ?? null,
);

const isLinked = computed(() => currentTournamentId.value !== null);

const filteredCandidates = computed(() => {
    if (!search.value) return candidates.value;
    const q = search.value.toLowerCase();
    return candidates.value.filter((c) => {
        const haystack = [c.eventId?.toString(), c.format, c.type, c.startedAt, c.scheduledAt]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return haystack.includes(q);
    });
});

async function loadCandidates() {
    if (matchId.value === null) return;

    loading.value = true;
    try {
        const url = CandidatesController.url({
            query: { match_id: matchId.value, all: showAll.value ? 1 : 0 },
        });
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        candidates.value = await response.json();
    } finally {
        loading.value = false;
    }
}

async function openForMatch(
    id: number,
    tournamentId: number | null,
    currentRound: number | null,
) {
    matchId.value = id;
    currentTournamentId.value = tournamentId;
    selectedTournamentId.value = tournamentId;
    round.value = currentRound;
    search.value = '';
    showAll.value = false;
    candidates.value = [];
    open.value = true;

    await loadCandidates();

    // If the current tournament isn't in the default list, fall back to all so pre-selection stays visible.
    if (tournamentId !== null && !candidates.value.some((c) => c.id === tournamentId)) {
        showAll.value = true;
        await loadCandidates();
    }
}

async function onToggleShowAll(value: boolean) {
    showAll.value = value;
    await loadCandidates();
}

const form = useForm<{ tournament_id: number | null; round: number | null }>({
    tournament_id: null,
    round: null,
});

function save() {
    if (matchId.value === null || selectedTournamentId.value === null || !round.value) return;

    form.tournament_id = selectedTournamentId.value;
    form.round = round.value;
    form.submit(LinkToTournamentController.store({ match: matchId.value }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function unlink() {
    if (matchId.value === null) return;
    router.delete(LinkToTournamentController.destroy({ match: matchId.value }).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

function formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return (
        d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) +
        ' ' +
        d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
    );
}

defineExpose({ openForMatch });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[80vh] flex-col">
            <DialogHeader>
                <DialogTitle>Link to tournament</DialogTitle>
                <DialogDescription>
                    Pick a tournament and enter the round this match belongs to.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center justify-between gap-3">
                <Input v-model="search" placeholder="Search tournaments..." class="flex-1" />
                <div class="flex items-center gap-2">
                    <Label for="show-all" class="text-xs text-muted-foreground">Show more</Label>
                    <Switch id="show-all" :model-value="showAll" @update:model-value="onToggleShowAll" />
                </div>
            </div>

            <div class="flex-1 space-y-0.5 overflow-y-auto rounded-md border">
                <p v-if="loading" class="py-6 text-center text-sm text-muted-foreground">Loading...</p>
                <p v-else-if="filteredCandidates.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                    No tournaments match. Toggle "Show more" to broaden the list.
                </p>
                <button
                    v-for="candidate in filteredCandidates"
                    :key="candidate.id"
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted"
                    :class="{ 'bg-muted': candidate.id === selectedTournamentId }"
                    @click="selectedTournamentId = candidate.id"
                >
                    <div class="flex flex-col">
                        <span class="font-medium">
                            {{ candidate.format ?? candidate.type ?? 'Tournament' }} #{{ candidate.eventId ?? candidate.id }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ formatDate(candidate.startedAt ?? candidate.scheduledAt) }}
                        </span>
                    </div>
                    <span class="text-xs text-muted-foreground">{{ candidate.type }}</span>
                </button>
            </div>

            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <Label for="round" class="text-xs">Round</Label>
                    <Input
                        id="round"
                        v-model.number="round"
                        type="number"
                        :min="1"
                        :max="selectedTournament?.maxRounds ?? undefined"
                        placeholder="e.g. 3"
                    />
                </div>
            </div>

            <DialogFooter class="flex items-center justify-between gap-2">
                <Button v-if="isLinked" variant="destructive" :disabled="form.processing" @click="unlink">
                    Unlink
                </Button>
                <div class="ml-auto flex gap-2">
                    <Button variant="outline" @click="open = false">Cancel</Button>
                    <Button
                        :disabled="!selectedTournamentId || !round || form.processing"
                        @click="save"
                    >
                        Save
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
