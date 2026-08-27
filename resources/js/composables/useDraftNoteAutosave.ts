import UpdatePickNoteController from '@/actions/App/Http/Controllers/Limited/UpdatePickNoteController';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch, type ComputedRef, type Ref } from 'vue';

export type DraftNoteStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

/** The pick the editor is pointed at. The caller decides which one that is. */
export type DraftNoteTarget = {
    draftId: number | null;
    leagueId: number | null;
    ordinal: number | null;
    note: string | null;
};

/**
 * `ActiveKey` is named explicitly rather than derived with `typeof active`:
 * a `typeof` query on a `let` variable picks up its control-flow-narrowed
 * type at that source position, and right after `active = null` that
 * narrows to the literal `null`, not the declared union.
 */
type ActiveKey = { draftId: number; leagueId: number; ordinal: number };

/**
 * Debounced autosave for one draft pick's note, with a sessionStorage
 * fallback so unsaved text survives the reloads each draft event triggers.
 *
 * The target is a computed rather than a prop read so the caller can point
 * this at a pinned past pick as easily as at the live one; switching target
 * flushes the outgoing note before adopting the incoming one.
 */
export function useDraftNoteAutosave(target: ComputedRef<DraftNoteTarget>): {
    text: Ref<string>;
    status: Ref<DraftNoteStatus>;
    flush: () => void;
} {
    const text = ref('');
    const status = ref<DraftNoteStatus>('idle');
    let timer: ReturnType<typeof setTimeout> | null = null;

    /**
     * The pick being edited. Captured, not read from the target inside the
     * save path: a reload or a keypress can swap the target while a save for
     * the previous pick is in flight, and that save must land on the old one.
     */
    let active: ActiveKey | null = null;
    let pending: { key: ActiveKey | null; note: string } | null = null;

    /** Key for the unsaved draft of one pick. */
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
     * True while `adopt()` is writing server state into `text`. The debounce
     * watcher below runs with `flush: 'sync'`, so it fires inside the
     * assignment and can tell an adopted value from something the player typed.
     */
    let adopting = false;

    function setText(value: string): void {
        adopting = true;
        text.value = value;
        adopting = false;
    }

    function adopt(): void {
        const t = target.value;
        if (t.draftId === null || t.ordinal === null || t.leagueId === null) {
            // No editable pick: the draft went away, or it was never linked to
            // a league. Send anything outstanding before it disappears.
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

        const changed = !active || active.draftId !== t.draftId || active.ordinal !== t.ordinal || active.leagueId !== t.leagueId;
        if (changed) {
            flush();
            active = { draftId: t.draftId, leagueId: t.leagueId, ordinal: t.ordinal };
            const stored = readStored(t.draftId, t.ordinal);
            setText(stored ?? t.note ?? '');
            status.value = stored !== null && stored !== (t.note ?? '') ? 'dirty' : 'idle';
            if (status.value === 'dirty') schedule();
            return;
        }

        // Same pick, fresh props (a poll or event reload). Never clobber typing.
        if (status.value === 'idle' || status.value === 'saved') {
            setText(t.note ?? '');
        }
    }

    watch(() => [target.value.draftId, target.value.ordinal, target.value.leagueId, target.value.note], adopt);

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

    onMounted(adopt);
    onBeforeUnmount(flush);

    return { text, status, flush };
}
