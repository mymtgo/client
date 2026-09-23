<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, useTemplateRef } from 'vue';
import type { ReplayTurn, ScrubHover } from './types';

/** A turn segment narrower than this hides its T-label. */
const MIN_LABEL_WIDTH_PX = 36;

const props = defineProps<{
    turns: ReplayTurn[];
    current: number;
    total: number;
    localId: number | null;
    playerName: (id: number | undefined) => string;
}>();

const emit = defineEmits<{
    seek: [frame: number];
    scrubStart: [];
    hover: [payload: ScrubHover | null];
}>();

const track = useTemplateRef<HTMLElement>('track');
const { width } = useElementSize(track);
let dragging = false;

const segments = computed(() =>
    props.turns.map((turn, i) => {
        const span = turn.to - turn.from;
        const widthPct = (span / props.total) * 100;
        const mine = turn.player !== null && turn.player === props.localId;

        return {
            key: i,
            turn,
            left: (turn.from / props.total) * 100,
            width: widthPct,
            mine,
            fill: Math.max(0, Math.min(100, ((props.current + 1 - turn.from) / span) * 100)),
            showLabel: turn.number !== null && (widthPct / 100) * width.value >= MIN_LABEL_WIDTH_PX,
            title: `Jump to turn ${turn.number}${turn.player !== null ? ` · ${props.playerName(turn.player)}` : ''}`,
        };
    }),
);

const knob = computed(() => ((props.current + 0.5) / props.total) * 100);

function frameAt(event: PointerEvent, rect: DOMRect): number {
    return Math.max(0, Math.min(props.total - 1, Math.floor(((event.clientX - rect.left) / rect.width) * props.total)));
}

function onDown(event: PointerEvent) {
    if (!track.value) {
        return;
    }

    emit('scrubStart');
    dragging = true;
    track.value.setPointerCapture(event.pointerId);
    emit('seek', frameAt(event, track.value.getBoundingClientRect()));
}

function onMove(event: PointerEvent) {
    if (!track.value) {
        return;
    }

    const rect = track.value.getBoundingClientRect();
    const frame = frameAt(event, rect);

    if (dragging) {
        emit('seek', frame);
    }

    emit('hover', { frame, clientX: event.clientX, trackTop: rect.top });
}

function onUp() {
    dragging = false;
}

function onLeave() {
    if (!dragging) {
        emit('hover', null);
    }
}

function jump(turn: ReplayTurn) {
    emit('scrubStart');
    emit('seek', turn.from);
}
</script>

<template>
    <div
        ref="track"
        class="relative order-3 h-7.5 min-w-0 flex-[1_1_200px] cursor-pointer touch-none rounded-md bg-background"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointerleave="onLeave"
    >
        <div
            v-for="segment in segments"
            :key="segment.key"
            class="absolute inset-y-0 box-border overflow-hidden px-0.5 py-0.75"
            :style="{ left: `${segment.left}%`, width: `${segment.width}%` }"
        >
            <div class="relative h-full overflow-hidden rounded" :class="segment.mine ? 'bg-primary/20' : 'bg-foreground/7'">
                <div
                    class="absolute inset-y-0 left-0"
                    :class="segment.mine ? 'bg-primary/80' : 'bg-foreground/35'"
                    :style="{ width: `${segment.fill}%` }"
                />
            </div>
            <button
                v-if="segment.showLabel"
                type="button"
                :title="segment.title"
                class="absolute top-1/2 left-1.5 h-4.25 -translate-y-1/2 cursor-pointer rounded bg-card px-1.25 text-[10.5px] font-bold hover:bg-accent"
                @pointerdown.stop="jump(segment.turn)"
            >
                T{{ segment.turn.number }}
            </button>
        </div>
        <div
            class="pointer-events-none absolute -inset-y-1 -ml-[1.5px] w-0.75 rounded-sm bg-foreground shadow-[0_0_0_2px_var(--card)] transition-[left] duration-100 ease-linear"
            :style="{ left: `${knob}%` }"
        />
    </div>
</template>
