<script setup lang="ts">
import { computed, ref } from 'vue'
import type { GameTimelineEvent } from './GameReplay.vue'

const props = defineProps<{
    events: GameTimelineEvent[]
    currentIndex: number
}>()

const emit = defineEmits<{
    seek: [index: number]
}>()

const trackRef = ref<HTMLElement | null>(null)

// For large timelines, we simplify markers but still allow clicking
const MAX_VISIBLE_MARKERS = 100
const showAllMarkers = computed(() => props.events.length <= MAX_VISIBLE_MARKERS)

// Calculate marker positions (evenly distributed by index, not by timestamp)
const markerPositions = computed(() => {
    if (props.events.length <= 1) return [50]
    return props.events.map((_, i) => (i / (props.events.length - 1)) * 100)
})

// Current playhead position
const playheadPosition = computed(() => {
    if (props.events.length <= 1) return 50
    return (props.currentIndex / (props.events.length - 1)) * 100
})

function handleTrackClick(event: MouseEvent) {
    if (!trackRef.value) return
    const rect = trackRef.value.getBoundingClientRect()
    const x = event.clientX - rect.left
    const percent = x / rect.width

    // Snap to nearest event
    const targetIndex = Math.round(percent * (props.events.length - 1))
    const clampedIndex = Math.max(0, Math.min(targetIndex, props.events.length - 1))
    emit('seek', clampedIndex)
}

function handleMarkerClick(index: number, event: MouseEvent) {
    event.stopPropagation()
    emit('seek', index)
}
</script>

<template>
    <div class="border bg-card p-3">
        <!-- Timeline track -->
        <div
            ref="trackRef"
            class="relative h-8 cursor-pointer rounded bg-muted"
            @click="handleTrackClick"
        >
            <!-- Event markers -->
            <template v-if="showAllMarkers">
                <button
                    v-for="(event, index) in events"
                    :key="index"
                    class="absolute top-1 h-6 w-1 -translate-x-1/2 rounded-sm transition-colors"
                    :class="[
                        index === currentIndex
                            ? 'bg-primary'
                            : index < currentIndex
                              ? 'bg-primary/40'
                              : 'bg-muted-foreground/30'
                    ]"
                    :style="{ left: `${markerPositions[index]}%` }"
                    :title="event.timestamp"
                    @click="handleMarkerClick(index, $event)"
                />
            </template>
            <!-- Simplified markers for large timelines -->
            <template v-else>
                <div class="absolute inset-x-0 top-1/2 h-0.5 -translate-y-1/2 bg-muted-foreground/20" />
            </template>

            <!-- Playhead -->
            <div
                class="absolute top-0 h-full w-1 -translate-x-1/2 rounded bg-primary shadow-md transition-[left] duration-100"
                :style="{ left: `${playheadPosition}%` }"
            />
        </div>

        <!-- Timestamp labels for start/end -->
        <div class="mt-1 flex justify-between text-xs text-muted-foreground">
            <span>{{ events[0]?.timestamp ?? '' }}</span>
            <span v-if="events.length > 1">{{ events[events.length - 1]?.timestamp ?? '' }}</span>
        </div>
    </div>
</template>
