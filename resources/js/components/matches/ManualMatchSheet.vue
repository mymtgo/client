<script setup lang="ts">
import StoreController from '@/actions/App/Http/Controllers/Matches/StoreController';
import ArchetypeSelect from '@/components/overlay/ArchetypeSelect.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { ManualLeagueDeckOption, ManualMatchLeagueOption } from '@/types/leagues';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type GameRow = { result: 'W' | 'L' | ''; onPlay: 'play' | 'draw'; turns: string | number };

const props = defineProps<{
    decks: ManualLeagueDeckOption[];
    archetypes: App.Data.Front.ArchetypeData[];
    leagues: ManualMatchLeagueOption[];
    /** Fix the deck and hide the deck select (deck > matches page). */
    deckId?: number;
    /** Fix the league and hide the league select (league card). */
    leagueId?: number;
}>();

const MATCH_MINUTES = 45;

const open = ref(false);
const endedAtTouched = ref(false);

function toLocalInput(date: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function plusMinutes(value: string, minutes: number): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    date.setMinutes(date.getMinutes() + minutes);
    return toLocalInput(date);
}

function emptyGames(): GameRow[] {
    return [
        { result: '', onPlay: 'play', turns: '' },
        { result: '', onPlay: 'draw', turns: '' },
        { result: '', onPlay: 'play', turns: '' },
    ];
}

const form = useForm({
    deck_id: (props.deckId ?? null) as number | null,
    opponent_name: '',
    archetype_id: null as number | null,
    league_id: (props.leagueId ?? null) as number | null,
    started_at: toLocalInput(new Date()),
    ended_at: plusMinutes(toLocalInput(new Date()), MATCH_MINUTES),
    games: emptyGames(),
});

const selectedDeck = computed(() => props.decks.find((d) => d.id === form.deck_id) ?? null);
const formatCode = computed(() => selectedDeck.value?.formatCode ?? null);

const leagueOptions = computed(() =>
    props.leagues.filter((l) => l.matchCount < l.roundCount && (l.deckId === null || form.deck_id === null || l.deckId === form.deck_id)),
);

const selectedArchetypeName = computed(() => props.archetypes.find((a) => a.id === form.archetype_id)?.name ?? null);

const resultOptions = [
    { value: 'W', label: 'Win' },
    { value: 'L', label: 'Loss' },
];
const playOptions = [
    { value: 'play', label: 'Play' },
    { value: 'draw', label: 'Draw' },
];

watch(
    () => form.started_at,
    (value) => {
        if (!endedAtTouched.value) {
            form.ended_at = plusMinutes(value, MATCH_MINUTES);
        }
    },
);

watch(
    () => form.deck_id,
    () => {
        form.archetype_id = null;
        if (props.leagueId === undefined && form.league_id !== null && !leagueOptions.value.some((l) => l.id === form.league_id)) {
            form.league_id = null;
        }
    },
);

function openSheet(preset?: { leagueId?: number }): void {
    form.reset();
    form.clearErrors();
    form.deck_id = props.deckId ?? null;
    form.league_id = preset?.leagueId ?? props.leagueId ?? null;
    form.started_at = toLocalInput(new Date());
    form.ended_at = plusMinutes(form.started_at, MATCH_MINUTES);
    form.games = emptyGames();
    endedAtTouched.value = false;

    const presetLeague = form.league_id !== null ? props.leagues.find((l) => l.id === form.league_id) : null;
    if (presetLeague?.deckId && form.deck_id === null) {
        form.deck_id = presetLeague.deckId;
    }

    open.value = true;
}

function toNullableNumber(value: unknown): number | null {
    if (value === '' || value === null || value === undefined) return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Rows unlock in order. A game is playable only when every game before it has
 * a result and the match is still undecided (neither side on two wins). A
 * 1-1 after two games is a valid draw and can be submitted as is.
 */
function isGameEnabled(index: number): boolean {
    if (index === 0) return true;
    const previous = form.games.slice(0, index);
    if (previous.some((g) => g.result === '')) return false;
    const wins = previous.filter((g) => g.result === 'W').length;
    const losses = previous.filter((g) => g.result === 'L').length;
    return wins < 2 && losses < 2;
}

function setResult(index: number, value: string): void {
    if (!isGameEnabled(index)) return;
    const current = form.games[index].result;
    form.games[index].result = current === value ? '' : (value as GameRow['result']);

    // Later rows depend on this one; clear anything that no longer unlocks.
    for (let i = index + 1; i < form.games.length; i++) {
        if (!isGameEnabled(i)) form.games[i].result = '';
    }
}

function setOnPlay(index: number, value: string): void {
    form.games[index].onPlay = value as GameRow['onPlay'];
}

function onEndedAtInput(value: string | number): void {
    endedAtTouched.value = true;
    form.ended_at = String(value);
}

/** The Input component emits numbers for type="number" and strings when cleared. */
function parseTurns(value: string | number): number | null {
    const parsed = typeof value === 'number' ? value : Number.parseInt(value.trim(), 10);
    return Number.isFinite(parsed) ? parsed : null;
}

const playedGames = computed(() => form.games.filter((g) => g.result !== ''));
const turnsError = computed(() => Object.entries(form.errors).find(([key]) => /^games\.\d+\.turns$/.test(key))?.[1] ?? null);
const canSubmit = computed(() => form.deck_id !== null && form.opponent_name.trim() !== '' && playedGames.value.length > 0 && !form.processing);

function submit(): void {
    form.transform((data) => ({
        ...data,
        games: data.games
            .filter((g) => g.result !== '')
            .map((g) => ({ won: g.result === 'W', on_play: g.onPlay === 'play', turns: parseTurns(g.turns) })),
    })).submit(StoreController(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

defineExpose({ open: openSheet });
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="flex w-[480px] flex-col gap-0 overflow-y-auto p-0 sm:max-w-[480px]">
            <SheetHeader class="border-b border-border p-4">
                <SheetTitle>Add manual match</SheetTitle>
                <SheetDescription>Record a match the tracker did not see. It counts everywhere except the shared stats.</SheetDescription>
            </SheetHeader>

            <form class="flex flex-1 flex-col gap-5 p-4" @submit.prevent="submit">
                <div v-if="deckId === undefined" class="flex flex-col gap-1.5">
                    <Label for="manual-deck">Deck</Label>
                    <Select
                        :model-value="form.deck_id !== null ? String(form.deck_id) : ''"
                        @update:model-value="form.deck_id = toNullableNumber($event)"
                    >
                        <SelectTrigger id="manual-deck" class="w-full">
                            <SelectValue placeholder="Select a deck" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="d in decks" :key="d.id" :value="String(d.id)">
                                {{ d.name }} <span class="text-xs text-muted-foreground">({{ d.format }})</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.deck_id" class="text-xs text-destructive">{{ form.errors.deck_id }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="manual-opponent">Opponent</Label>
                    <Input id="manual-opponent" v-model="form.opponent_name" placeholder="MTGO username" autocomplete="off" />
                    <p v-if="form.errors.opponent_name" class="text-xs text-destructive">{{ form.errors.opponent_name }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label>Opponent archetype</Label>
                    <ArchetypeSelect
                        :archetypes="archetypes"
                        :format="formatCode"
                        :current-archetype-id="form.archetype_id"
                        :current-archetype-name="selectedArchetypeName"
                        :disabled="form.deck_id === null"
                        placeholder="Select an archetype"
                        trigger-class="bevel h-9 border-black/60 bg-linear-to-t from-neutral-900 to-background px-4 text-xs font-normal hover:bg-input/50 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none"
                        @select="form.archetype_id = $event"
                    />
                    <p v-if="form.errors.archetype_id" class="text-xs text-destructive">{{ form.errors.archetype_id }}</p>
                </div>

                <div v-if="leagueId === undefined" class="flex flex-col gap-1.5">
                    <Label for="manual-league">League</Label>
                    <Select
                        :model-value="form.league_id !== null ? String(form.league_id) : 'none'"
                        :disabled="form.deck_id === null"
                        @update:model-value="form.league_id = $event === 'none' ? null : toNullableNumber($event)"
                    >
                        <SelectTrigger id="manual-league" class="w-full">
                            <SelectValue placeholder="No league" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">No league</SelectItem>
                            <SelectItem v-for="l in leagueOptions" :key="l.id" :value="String(l.id)">
                                {{ l.name }} <span class="text-xs text-muted-foreground">({{ l.matchCount }}/{{ l.roundCount }})</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.league_id" class="text-xs text-destructive">{{ form.errors.league_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1.5">
                        <Label for="manual-started">Started</Label>
                        <Input id="manual-started" v-model="form.started_at" type="datetime-local" />
                        <p v-if="form.errors.started_at" class="text-xs text-destructive">{{ form.errors.started_at }}</p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="manual-ended">Ended</Label>
                        <Input id="manual-ended" :model-value="form.ended_at" type="datetime-local" @update:model-value="onEndedAtInput" />
                        <p v-if="form.errors.ended_at" class="text-xs text-destructive">{{ form.errors.ended_at }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <Label>Games</Label>
                    <div
                        v-for="(game, index) in form.games"
                        :key="index"
                        class="flex items-center justify-between gap-3 rounded-md border border-border p-2 transition-opacity"
                        :class="isGameEnabled(index) ? '' : 'opacity-50'"
                    >
                        <span class="w-14 text-xs font-medium text-muted-foreground">Game {{ index + 1 }}</span>
                        <SegmentedControl
                            :model-value="game.result"
                            :options="resultOptions"
                            :disabled="!isGameEnabled(index)"
                            @update:model-value="setResult(index, $event)"
                        />
                        <SegmentedControl
                            :model-value="game.onPlay"
                            :options="playOptions"
                            :disabled="!isGameEnabled(index)"
                            @update:model-value="setOnPlay(index, $event)"
                        />
                        <Input
                            v-model="game.turns"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="99"
                            placeholder="Turns"
                            :aria-label="`Game ${index + 1} turns`"
                            :disabled="!isGameEnabled(index)"
                            class="h-8 w-20 text-xs"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Games unlock in order and stop at two wins. A 1-1 after two games is saved as a draw. Click a result again to clear it. Turns
                        are optional.
                    </p>
                    <p v-if="form.errors.games" class="text-xs text-destructive">{{ form.errors.games }}</p>
                    <p v-if="turnsError" class="text-xs text-destructive">{{ turnsError }}</p>
                </div>

                <SheetFooter class="mt-auto flex-row justify-end gap-2 border-t border-border pt-4">
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="!canSubmit">Add match</Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
