<script setup lang="ts">
import { computed, ref, watch, onUnmounted } from 'vue';
import GameReplayControls from './GameReplayControls.vue';
import GameReplayTimeline from './GameReplayTimeline.vue';
import GameReplaySnapshot from './GameReplaySnapshot.vue';

export type GameTimelineEvent = {
    timestamp: string;
    content: Array<any>;
};

const props = defineProps<{
    timeline: GameTimelineEvent[];
}>();

// Sort events by timestamp, preserving original order for duplicates
const sortedEvents = computed(() => {
    if (!props.timeline || props.timeline.length === 0) return [];
    return [...props.timeline].sort((a, b) => {
        return a.timestamp.localeCompare(b.timestamp);
    });
});

const currentIndex = ref(0);
const isPlaying = ref(false);
const playbackSpeed = ref(1);
const playbackTimer = ref<ReturnType<typeof setTimeout> | null>(null);

// Base interval between events (ms) - gaps are compressed to this max
const BASE_INTERVAL = 1000;
const MAX_GAP = 2000;

const currentEvent = computed(() => {
    if (sortedEvents.value.length === 0) return null;
    return sortedEvents.value[currentIndex.value] ?? null;
});

const hasEvents = computed(() => sortedEvents.value.length > 0);
const hasSingleEvent = computed(() => sortedEvents.value.length === 1);
const isAtStart = computed(() => currentIndex.value === 0);
const isAtEnd = computed(() => currentIndex.value >= sortedEvents.value.length - 1);

function parseTimestamp(ts: string): number {
    const parts = ts.split(':').map(Number);
    return (parts[0] ?? 0) * 3600 + (parts[1] ?? 0) * 60 + (parts[2] ?? 0);
}

function getIntervalToNext(): number {
    if (isAtEnd.value) return 0;
    const current = sortedEvents.value[currentIndex.value];
    const next = sortedEvents.value[currentIndex.value + 1];
    if (!current || !next) return BASE_INTERVAL;

    const currentSec = parseTimestamp(current.timestamp);
    const nextSec = parseTimestamp(next.timestamp);
    const gap = (nextSec - currentSec) * 1000;

    // Compress gaps: use actual gap up to MAX_GAP, then cap it
    const interval = Math.min(Math.max(gap, BASE_INTERVAL), MAX_GAP);
    return interval / playbackSpeed.value;
}

function stepNext() {
    if (!isAtEnd.value) {
        currentIndex.value++;
    }
}

function stepPrev() {
    if (!isAtStart.value) {
        currentIndex.value--;
    }
}

function goToEvent(index: number) {
    if (index >= 0 && index < sortedEvents.value.length) {
        currentIndex.value = index;
    }
}

function play() {
    if (hasSingleEvent.value || isAtEnd.value) return;
    isPlaying.value = true;
    scheduleNext();
}

function pause() {
    isPlaying.value = false;
    if (playbackTimer.value) {
        clearTimeout(playbackTimer.value);
        playbackTimer.value = null;
    }
}

function togglePlay() {
    if (isPlaying.value) {
        pause();
    } else {
        play();
    }
}

function scheduleNext() {
    if (!isPlaying.value || isAtEnd.value) {
        pause();
        return;
    }
    const interval = getIntervalToNext();
    playbackTimer.value = setTimeout(() => {
        stepNext();
        scheduleNext();
    }, interval);
}

function setSpeed(speed: number) {
    playbackSpeed.value = speed;
    // If playing, restart scheduling with new speed
    if (isPlaying.value) {
        if (playbackTimer.value) {
            clearTimeout(playbackTimer.value);
        }
        scheduleNext();
    }
}

// Stop playback when reaching end
watch(isAtEnd, (atEnd) => {
    if (atEnd && isPlaying.value) {
        pause();
    }
});

onUnmounted(() => {
    if (playbackTimer.value) {
        clearTimeout(playbackTimer.value);
    }
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Empty state -->
        <div v-if="!hasEvents" class="border border-dashed p-8 text-center text-muted-foreground">
            No timeline events available for replay.
        </div>

        <!-- Replay UI -->
        <template v-else>
            <!-- Snapshot display -->
            <GameReplaySnapshot :event="currentEvent" />

            <!-- Timeline scrubber -->
            <GameReplayTimeline :events="sortedEvents" :current-index="currentIndex" @seek="goToEvent" />

            <!-- Controls -->
            <GameReplayControls
                :is-playing="isPlaying"
                :is-at-start="isAtStart"
                :is-at-end="isAtEnd"
                :can-play="!hasSingleEvent"
                :current-timestamp="currentEvent?.timestamp ?? ''"
                :current-index="currentIndex"
                :total-events="sortedEvents.length"
                :playback-speed="playbackSpeed"
                @toggle-play="togglePlay"
                @step-prev="stepPrev"
                @step-next="stepNext"
                @set-speed="setSpeed"
            />
        </template>
    </div>
</template>
