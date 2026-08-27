<script setup lang="ts">
import UpdatePickNoteController from '@/actions/App/Http/Controllers/Limited/UpdatePickNoteController';
import { timeLabel, type DraftPick } from '@/types/limited';
import { router } from '@inertiajs/vue3';
import { Check, Loader2 } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{ leagueId: number; pick: DraftPick }>();

const draft = ref(props.pick.note ?? '');
const status = ref<'idle' | 'dirty' | 'saving' | 'saved' | 'error'>('idle');
const savedAt = ref<string | null>(props.pick.noteSavedAt);
let timer: ReturnType<typeof setTimeout> | null = null;

/**
 * The ordinal of the pick currently being edited. Deliberately not read from
 * `props.pick` inside the save path: Vue patches props to the new pick
 * before the watcher below runs, so a save triggered from there (or from a
 * stale closure) would otherwise write the wrong pick's text to the wrong
 * ordinal.
 */
let activeOrdinal = props.pick.ordinal;

/** Snapshot of what to save, captured at typing time against `activeOrdinal`. */
let pending: { ordinal: number; note: string } | null = null;

watch(
    () => props.pick.ordinal,
    (newOrdinal) => {
        flush();
        activeOrdinal = newOrdinal;
        draft.value = props.pick.note ?? '';
        savedAt.value = props.pick.noteSavedAt;
        status.value = 'idle';
    },
);

function schedule() {
    pending = { ordinal: activeOrdinal, note: draft.value };
    status.value = 'dirty';
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => save(pending!), 600);
}

function flush() {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
    if (status.value === 'dirty' && pending) {
        save(pending);
    }
}

function save(snapshot: { ordinal: number; note: string }) {
    const savingOrdinal = snapshot.ordinal;
    status.value = 'saving';
    pending = null;
    router.patch(
        UpdatePickNoteController.url({ league: props.leagueId, ordinal: savingOrdinal }),
        { note: snapshot.note },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['review'],
            onSuccess: () => {
                if (activeOrdinal !== savingOrdinal) return;
                status.value = 'saved';
                savedAt.value = new Date().toISOString();
            },
            onError: () => {
                if (activeOrdinal !== savingOrdinal) return;
                status.value = 'error';
            },
        },
    );
}

onBeforeUnmount(flush);
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between text-xs">
            <span class="font-semibold">Your note</span>
            <span class="inline-flex items-center gap-1 text-muted-foreground">
                <Loader2 v-if="status === 'saving'" class="size-3 animate-spin" />
                <Check v-else-if="status === 'saved' || (status === 'idle' && savedAt)" class="size-3 text-emerald-400" />
                <span v-if="status === 'error'" class="text-rose-400">not saved</span>
                <span v-else-if="status === 'dirty'">unsaved</span>
                <span v-else-if="savedAt">saved {{ timeLabel(savedAt) }}</span>
            </span>
        </div>
        <textarea
            v-model="draft"
            rows="5"
            maxlength="2000"
            placeholder="Why this pick?"
            class="w-full resize-none rounded-md border border-black/60 bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:ring-2 focus:ring-primary focus:outline-none"
            @input="schedule"
            @blur="flush"
        />
    </div>
</template>
