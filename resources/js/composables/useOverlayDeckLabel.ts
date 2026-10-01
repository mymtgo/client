import UpdateOverlayDeckLabelController from '@/actions/App/Http/Controllers/Settings/UpdateOverlayDeckLabelController';
import { xsrfToken } from '@/lib/xsrf';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch, type Ref } from 'vue';

const LEGACY_PREFIX = 'league-overlay-label:';

/**
 * A streamer-chosen name for the deck on the league overlay. Stored in app
 * settings (so the hosted OBS page sees it); starts from the payload's label.
 * Incoming reloads never overwrite typing: they are ignored while any keystroke
 * is unsaved, and after a save until the server echoes that save back (so an
 * older poll response landing late cannot flicker the old label in).
 */
export function useOverlayDeckLabel(deckId: Ref<number | null>, initial: Ref<string | null>) {
    const label = ref('');
    /** Keystrokes so far, and how many of them the last finished save covered. */
    let edits = 0;
    let savedEdits = 0;
    /** The value the server should echo back after our save; reloads that differ are older than it. */
    let awaiting: string | null = null;

    const persist = useDebounceFn(async (id: number, value: string) => {
        const covering = edits;
        if (await save(id, value)) {
            awaiting = normalise(value);
        }
        savedEdits = Math.max(savedEdits, covering);
    }, 500);

    watch(
        deckId,
        (id) => {
            edits = savedEdits = 0;
            awaiting = null;
            label.value = id === null ? '' : (initial.value ?? '');
            if (id !== null && initial.value === null) migrateLegacy(id);
        },
        { immediate: true },
    );

    watch(initial, (value) => {
        if (deckId.value === null || edits !== savedEdits) return;
        if (awaiting !== null) {
            if ((value ?? '') !== awaiting) return;
            awaiting = null;
        }
        label.value = value ?? '';
    });

    function setLabel(value: string) {
        label.value = value;
        if (deckId.value === null) return;
        edits++;
        void persist(deckId.value, value);
    }

    /** One-time move of a label saved by the localStorage version of this composable. */
    function migrateLegacy(id: number) {
        let legacy: string | null = null;
        try {
            legacy = window.localStorage.getItem(LEGACY_PREFIX + id);
        } catch {
            return;
        }
        if (!legacy) return;

        label.value = legacy;
        void save(id, legacy).then((ok) => {
            if (!ok) return;
            try {
                window.localStorage.removeItem(LEGACY_PREFIX + id);
            } catch {
                /* storage unavailable: harmless, the server copy now wins */
            }
        });
    }

    return { label, setLabel };
}

/** What the server stores for a typed value: trimmed and capped at 40 characters. */
function normalise(value: string): string {
    return [...value.trim()].slice(0, 40).join('');
}

async function save(deckId: number, value: string): Promise<boolean> {
    const definition = UpdateOverlayDeckLabelController.put();
    try {
        const response = await fetch(definition.url, {
            method: definition.method.toUpperCase(),
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ deck_id: deckId, label: value.trim() === '' ? null : value }),
        });
        return response.ok;
    } catch {
        return false;
    }
}
