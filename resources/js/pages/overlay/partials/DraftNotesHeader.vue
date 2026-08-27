<script setup lang="ts">
import type { DraftNoteStatus } from '@/composables/useDraftNoteAutosave';
import { Check, ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pick: App.Data.Front.DraftNotePickData | null;
    hasDraft: boolean;
    state: string | null;
    isLive: boolean;
    picksAhead: number;
    canPrev: boolean;
    canNext: boolean;
    secondsLeft: number | null;
    status: DraftNoteStatus;
}>();

defineEmits<{ prev: []; next: []; live: [] }>();

/**
 * `0:41`, `1:05`. The header always reads as a clock, where formatSeconds
 * drops to a bare `41s` under a minute.
 */
function mmss(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

const label = computed<string>(() => {
    if (!props.hasDraft) return 'No draft in progress';
    if (!props.pick) return props.state === 'connecting' ? 'Joining draft' : 'Waiting for pack';
    if (props.pick.pickedCatalogId !== null) return `${props.pick.label} · ${props.pick.pickedName ?? `#${props.pick.pickedCatalogId}`}`;

    const parts = [props.pick.label];
    if (props.pick.cardsInPack > 0) parts.push(`${props.pick.cardsInPack} cards`);
    if (props.secondsLeft !== null) parts.push(`${mmss(props.secondsLeft)} left`);
    return parts.join(' · ');
});

const urgent = computed(() => props.secondsLeft !== null && props.secondsLeft <= 10 && props.pick?.pickedCatalogId === null);
</script>

<template>
    <div class="flex items-center gap-1.5 text-xs font-semibold tracking-tight" style="-webkit-app-region: drag">
        <div v-if="hasDraft" class="flex shrink-0 items-center" style="-webkit-app-region: no-drag">
            <button
                type="button"
                class="rounded p-0.5 text-muted-foreground transition-colors hover:bg-white/5 hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                :disabled="!canPrev"
                title="Previous pick (Alt+←)"
                @click="$emit('prev')"
            >
                <ChevronLeft class="size-3.5" />
            </button>
            <button
                type="button"
                class="rounded p-0.5 text-muted-foreground transition-colors hover:bg-white/5 hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                :disabled="!canNext"
                title="Next pick (Alt+→)"
                @click="$emit('next')"
            >
                <ChevronRight class="size-3.5" />
            </button>
        </div>

        <span class="min-w-0 flex-1 truncate" :class="urgent ? 'text-rose-400' : ''">{{ label }}</span>

        <template v-if="pick">
            <span v-if="isLive" class="size-1.5 shrink-0 rounded-full bg-emerald-400" title="Following the current pick" />
            <button
                v-else
                type="button"
                class="shrink-0 rounded-full border border-white/10 px-1.5 py-px text-[10px] font-medium text-muted-foreground transition-colors hover:border-emerald-400/40 hover:text-emerald-400"
                style="-webkit-app-region: no-drag"
                title="Back to the current pick"
                @click="$emit('live')"
            >
                Live<span v-if="picksAhead > 0" class="tabular-nums"> +{{ picksAhead }}</span>
            </button>
        </template>

        <span class="inline-flex shrink-0 items-center gap-1 font-normal text-muted-foreground">
            <Loader2 v-if="status === 'saving'" class="size-3 animate-spin" />
            <Check v-else-if="status === 'saved'" class="size-3 text-emerald-400" />
            <span v-if="status === 'error'" class="text-rose-400">not saved</span>
            <span v-else-if="status === 'dirty'">unsaved</span>
            <span v-else-if="status === 'saved'">saved</span>
        </span>
    </div>
</template>
