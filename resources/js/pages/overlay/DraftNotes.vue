<script setup lang="ts">
import { useDraftNoteAutosave, type DraftNoteTarget } from '@/composables/useDraftNoteAutosave';
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import DraftNotesHeader from '@/pages/overlay/partials/DraftNotesHeader.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    notes: App.Data.Front.DraftNotesData | null;
    serverNow: string;
}>();

const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | null = null;

/** Milliseconds to add to the client clock to land on server time. Computed once at mount. */
const skew = new Date(props.serverNow).getTime() - Date.now();

/**
 * Which pick the editor is pointed at. The window follows the newest pick by
 * default; stepping with ‹ / › pins it to one pick so a pack landing mid-note
 * no longer drags the editor away from what you were writing about.
 */
const following = ref(true);
const pinnedOrdinal = ref<number | null>(null);

const picks = computed(() => props.notes?.picks ?? []);
const currentOrdinal = computed(() => props.notes?.currentOrdinal ?? null);
const selectedOrdinal = computed(() => (following.value ? currentOrdinal.value : pinnedOrdinal.value));
const selectedIndex = computed(() => picks.value.findIndex((candidate) => candidate.ordinal === selectedOrdinal.value));
const selectedPick = computed(() => picks.value[selectedIndex.value] ?? null);

const isLive = computed(() => selectedOrdinal.value !== null && selectedOrdinal.value === currentOrdinal.value);
const picksAhead = computed(() => (isLive.value || selectedIndex.value < 0 ? 0 : picks.value.length - 1 - selectedIndex.value));
const canPrev = computed(() => selectedIndex.value > 0);
const canNext = computed(() => selectedIndex.value >= 0 && selectedIndex.value < picks.value.length - 1);

function goTo(index: number): void {
    const pick = picks.value[index];
    if (!pick) return;
    pinnedOrdinal.value = pick.ordinal;
    // Stepping onto the newest pick means "live" again, so › and the Live chip
    // converge instead of leaving you pinned to the front of the draft.
    following.value = pick.ordinal === currentOrdinal.value;
}

function goPrev(): void {
    if (canPrev.value) goTo(selectedIndex.value - 1);
}

function goNext(): void {
    if (canNext.value) goTo(selectedIndex.value + 1);
}

function goLive(): void {
    following.value = true;
    pinnedOrdinal.value = null;
}

/** A new draft always starts live; a pin from the last one means nothing here. */
watch(() => props.notes?.draftId ?? null, goLive);

const target = computed<DraftNoteTarget>(() => ({
    draftId: props.notes?.draftId ?? null,
    leagueId: props.notes?.leagueId ?? null,
    ordinal: selectedPick.value?.ordinal ?? null,
    note: selectedPick.value?.note ?? null,
}));

const { text, status, flush } = useDraftNoteAutosave(target);

const secondsLeft = computed<number | null>(() => {
    // Only the pick on the table has a running clock. A pinned past pick's
    // deadline is history and would render as a stuck 0:00.
    if (!isLive.value) return null;
    const iso = selectedPick.value?.deadlineAt;
    if (!iso) return null;
    return Math.max(0, Math.round((new Date(iso).getTime() - (now.value + skew)) / 1000));
});

/**
 * A pick is editable only once it has an ordinal and a league: the save
 * endpoint is keyed by league, so an unlinked draft has nowhere to put a
 * note and typing into it would drop every keystroke.
 */
const canEdit = computed<boolean>(() => selectedPick.value !== null && (props.notes?.leagueId ?? null) !== null);

const placeholder = computed<string>(() => {
    if (props.notes !== null && props.notes.leagueId === null) {
        return 'This draft is not linked to a league yet, so notes cannot be saved.';
    }

    return canEdit.value ? 'Why this pick?' : 'Notes appear here once a pack is on the table.';
});

/**
 * Alt rather than a bare arrow: the textarea holds focus for most of a draft,
 * so an unmodified key would move the caret instead of the pick.
 */
function onKeydown(event: KeyboardEvent): void {
    if (!event.altKey || event.ctrlKey || event.metaKey) return;
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        goPrev();
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        goNext();
    }
}

/**
 * Poll as a backstop for a missed event; reload on the three draft events
 * for the fast path, the same mechanism GameOverlay.vue uses for
 * GameCardsSnapshotChanged. `serverNow` is excluded so skew is fixed once.
 */
usePoll(1000, { only: ['notes'] });

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);
    window.addEventListener('keydown', onKeydown);
    for (const event of ['App\\Events\\DraftPickPending', 'App\\Events\\DraftPickCommitted', 'App\\Events\\DraftEnded']) {
        window.Native?.on(event, () => router.reload({ only: ['notes'] }));
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    if (ticker) clearInterval(ticker);
});
</script>

<template>
    <div class="flex h-screen flex-col gap-2 bg-background p-3 text-foreground">
        <DraftNotesHeader
            :pick="selectedPick"
            :has-draft="notes !== null"
            :state="notes?.state ?? null"
            :is-live="isLive"
            :picks-ahead="picksAhead"
            :can-prev="canPrev"
            :can-next="canNext"
            :seconds-left="secondsLeft"
            :status="status"
            @prev="goPrev"
            @next="goNext"
            @live="goLive"
        />

        <textarea
            v-model="text"
            :disabled="!canEdit"
            maxlength="2000"
            :placeholder="placeholder"
            class="min-h-0 w-full flex-1 resize-none rounded-md border border-black/60 bg-card px-3 py-2 text-sm placeholder:text-muted-foreground focus:ring-2 focus:ring-primary focus:outline-none disabled:opacity-60"
            style="-webkit-app-region: no-drag"
            @blur="flush"
        />
    </div>
</template>
