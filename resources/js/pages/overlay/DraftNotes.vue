<script setup lang="ts">
import UpdatePickNoteController from '@/actions/App/Http/Controllers/Limited/UpdatePickNoteController';
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { Check, Loader2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    notes: App.Data.Front.DraftNotesData | null;
    serverNow: string;
}>();

type Status = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

const text = ref('');
const status = ref<Status>('idle');
const now = ref(Date.now());
let timer: ReturnType<typeof setTimeout> | null = null;
let ticker: ReturnType<typeof setInterval> | null = null;

/** Milliseconds to add to the client clock to land on server time. Computed once at mount. */
const skew = new Date(props.serverNow).getTime() - Date.now();

/** Key for the unsaved draft of one pick; survives the reloads each draft event triggers. */
function storageKey(draftId: number, ordinal: number): string {
    return `draft-notes:${draftId}:${ordinal}`;
}

function readStored(draftId: number, ordinal: number): string | null {
    try {
        return window.sessionStorage.getItem(storageKey(draftId, ordinal));
    } catch {
        return null;
    }
}

function writeStored(draftId: number, ordinal: number, value: string | null): void {
    try {
        if (value === null) {
            window.sessionStorage.removeItem(storageKey(draftId, ordinal));
        } else {
            window.sessionStorage.setItem(storageKey(draftId, ordinal), value);
        }
    } catch {
        // Storage can be unavailable; the server copy is the durable one.
    }
}

/**
 * The pick being edited. Captured, not read from props inside the save
 * path: a reload can swap `notes` to the next ordinal while a save for the
 * previous one is in flight, and that save must land on the old ordinal.
 *
 * `ActiveKey` is named explicitly rather than derived with `typeof active`:
 * a `typeof` query on a `let` variable picks up its control-flow-narrowed
 * type at that source position, and right after `active = null` that
 * narrows to the literal `null`, not the declared union.
 */
type ActiveKey = { draftId: number; leagueId: number; ordinal: number };

let active: ActiveKey | null = null;
let pending: { key: ActiveKey | null; note: string } | null = null;

/**
 * True while `adopt()` is writing server state into `text`. The debounce
 * watcher below runs with `flush: 'sync'`, so it fires inside the assignment
 * and can tell an adopted value from something the player typed.
 */
let adopting = false;

function setText(value: string): void {
    adopting = true;
    text.value = value;
    adopting = false;
}

function adopt(): void {
    const n = props.notes;
    if (!n || n.ordinal === null || n.leagueId === null) {
        // The draft went away (ended, abandoned, or never linked to a league).
        // Send anything outstanding before the editable pick disappears.
        flush();

        // flush() may have started a save for the pick being dropped. Leave
        // `active` and the text alone until it settles, or the onSuccess and
        // onError guards stop matching and the result is silently discarded.
        if (status.value === 'saving') {
            return;
        }

        active = null;
        setText('');
        status.value = 'idle';
        return;
    }

    const changed = !active || active.draftId !== n.draftId || active.ordinal !== n.ordinal || active.leagueId !== n.leagueId;
    if (changed) {
        flush();
        active = { draftId: n.draftId, leagueId: n.leagueId, ordinal: n.ordinal };
        const stored = readStored(n.draftId, n.ordinal);
        setText(stored ?? n.note ?? '');
        status.value = stored !== null && stored !== (n.note ?? '') ? 'dirty' : 'idle';
        if (status.value === 'dirty') schedule();
        return;
    }

    // Same pick, fresh props (a poll or event reload). Never clobber typing.
    if (status.value === 'idle' || status.value === 'saved') {
        setText(n.note ?? '');
    }
}

watch(() => [props.notes?.draftId, props.notes?.ordinal, props.notes?.leagueId, props.notes?.note], adopt);

/**
 * Debounce off the model rather than `@input`. v-model suppresses input
 * events for the duration of an IME composition and writes the ref on
 * compositionend, so an `@input` handler never sees a composed character.
 * `flush: 'sync'` keeps the `adopting` guard meaningful.
 */
watch(
    text,
    () => {
        if (adopting) return;
        schedule();
    },
    { flush: 'sync' },
);

function schedule(): void {
    if (!active) return;
    pending = { key: { ...active }, note: text.value };
    writeStored(active.draftId, active.ordinal, text.value);
    status.value = 'dirty';
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => pending && save(pending), 500);
}

function flush(): void {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
    if ((status.value === 'dirty' || status.value === 'error') && pending) save(pending);
}

function save(snapshot: { key: ActiveKey | null; note: string }): void {
    const key = snapshot.key;
    if (!key) return;
    pending = null;
    status.value = 'saving';
    router.patch(
        UpdatePickNoteController.url({ league: key.leagueId, ordinal: key.ordinal }),
        { note: snapshot.note },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['notes'],
            onSuccess: () => {
                writeStored(key.draftId, key.ordinal, null);
                if (!active || active.ordinal !== key.ordinal || active.draftId !== key.draftId) return;
                status.value = 'saved';
            },
            onError: () => {
                // Keep the snapshot so the next flush (blur, pick change,
                // unmount) retries instead of stranding the note in storage.
                pending = snapshot;
                if (!active || active.ordinal !== key.ordinal || active.draftId !== key.draftId) return;
                status.value = 'error';
            },
        },
    );
}

const secondsLeft = computed<number | null>(() => {
    const iso = props.notes?.deadlineAt;
    if (!iso) return null;
    const left = Math.round((new Date(iso).getTime() - (now.value + skew)) / 1000);
    return Math.max(0, left);
});

/**
 * `0:41`, `1:05`. The header always reads as a clock, where formatSeconds
 * drops to a bare `41s` under a minute.
 */
function mmss(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

const header = computed<string>(() => {
    const n = props.notes;
    if (!n) return 'No draft in progress';
    if (n.ordinal === null || n.label === null) return n.state === 'connecting' ? 'Joining draft' : 'Waiting for pack';
    if (n.pickedCatalogId !== null) return `${n.label} · ${n.pickedName ?? `#${n.pickedCatalogId}`}`;
    const parts = [n.label];
    if (n.cardsInPack !== null) parts.push(`${n.cardsInPack} cards`);
    if (secondsLeft.value !== null) parts.push(`${mmss(secondsLeft.value)} left`);
    return parts.join(' · ');
});

/**
 * A pick is editable only once it has an ordinal and a league: the save
 * endpoint is keyed by league, so an unlinked draft has nowhere to put a
 * note and typing into it would drop every keystroke.
 */
const canEdit = computed<boolean>(() => props.notes !== null && props.notes.ordinal !== null && props.notes.leagueId !== null);

const placeholder = computed<string>(() => {
    if (props.notes !== null && props.notes.leagueId === null) {
        return 'This draft is not linked to a league yet, so notes cannot be saved.';
    }

    return canEdit.value ? 'Why this pick?' : 'Notes appear here once a pack is on the table.';
});

const urgent = computed(() => secondsLeft.value !== null && secondsLeft.value <= 10 && props.notes?.pickedCatalogId === null);

/**
 * Poll as a backstop for a missed event; reload on the three draft events
 * for the fast path, the same mechanism GameOverlay.vue uses for
 * GameCardsSnapshotChanged. `serverNow` is excluded so skew is fixed once.
 */
usePoll(5000, { only: ['notes'] });

onMounted(() => {
    adopt();
    ticker = setInterval(() => (now.value = Date.now()), 1000);
    for (const event of ['App\\Events\\DraftPickPending', 'App\\Events\\DraftPickCommitted', 'App\\Events\\DraftEnded']) {
        window.Native?.on(event, () => router.reload({ only: ['notes'] }));
    }
});

onBeforeUnmount(() => {
    flush();
    if (ticker) clearInterval(ticker);
});
</script>

<template>
    <div class="flex h-screen flex-col gap-2 bg-background p-3 text-foreground">
        <div
            class="flex items-center justify-between gap-2 text-xs font-semibold tracking-tight"
            style="-webkit-app-region: drag"
        >
            <span class="truncate" :class="urgent ? 'text-rose-400' : ''">{{ header }}</span>
            <span class="inline-flex shrink-0 items-center gap-1 font-normal text-muted-foreground">
                <Loader2 v-if="status === 'saving'" class="size-3 animate-spin" />
                <Check v-else-if="status === 'saved'" class="size-3 text-emerald-400" />
                <span v-if="status === 'error'" class="text-rose-400">not saved</span>
                <span v-else-if="status === 'dirty'">unsaved</span>
                <span v-else-if="status === 'saved'">saved</span>
            </span>
        </div>

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
