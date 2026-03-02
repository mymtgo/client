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

const markerPositions = computed(() => {
    if (props.events.length <= 1) return [50]
    return props.events.map((_, i) => (i / (props.events.length - 1)) * 100)
})

const playheadPosition = computed(() => {
    if (props.events.length <= 1) return 50
    return (props.currentIndex / (props.events.length - 1)) * 100
})

// For very large timelines, sample markers to avoid rendering thousands of DOM elements
const MAX_MARKERS = 200
const sampledIndices = computed(() => {
    if (props.events.length <= MAX_MARKERS) {
        return props.events.map((_, i) => i)
    }
    const step = props.events.length / MAX_MARKERS
    const indices: number[] = []
    for (let i = 0; i < MAX_MARKERS; i++) {
        indices.push(Math.round(i * step))
    }
    // Always include current index
    if (!indices.includes(props.currentIndex)) {
        indices.push(props.currentIndex)
    }
    return indices.sort((a, b) => a - b)
})

function handleTrackClick(event: MouseEvent) {
    if (!trackRef.value) return
    const rect = trackRef.value.getBoundingClientRect()
    const x = event.clientX - rect.left
    const percent = x / rect.width
    const targetIndex = Math.round(percent * (props.events.length - 1))
    const clampedIndex = Math.max(0, Math.min(targetIndex, props.events.length - 1))
    emit('seek', clampedIndex)
}
</script>

<template>
    <div class="border bg-card rounded-lg p-3">
        <!-- Timeline track -->
        <div
            ref="trackRef"
            class="relative h-6 cursor-pointer rounded bg-muted"
            @click="handleTrackClick"
        >
            <!-- Event tick marks -->
            <button
                v-for="index in sampledIndices"
                :key="index"
                class="absolute top-1 bottom-1 w-0.5 -translate-x-1/2 rounded-full transition-colors"
                :class="[
                    index === currentIndex
                        ? 'bg-primary w-1'
                        : index < currentIndex
                          ? 'bg-primary/50'
                          : 'bg-muted-foreground/25'
                ]"
                :style="{ left: `${markerPositions[index]}%` }"
                :title="events[index]?.timestamp"
                @click.stop="emit('seek', index)"
            />

            <!-- Playhead -->
            <div
                class="absolute top-0 bottom-0 w-1 -translate-x-1/2 rounded bg-primary shadow-md ring-2 ring-primary/30 transition-[left] duration-100"
                :style="{ left: `${playheadPosition}%` }"
            />
        </div>

        <!-- Timestamp labels -->
        <div class="mt-1 flex justify-between text-xs text-muted-foreground">
            <span>{{ events[0]?.timestamp ?? '' }}</span>
            <span v-if="events.length > 1">{{ events[events.length - 1]?.timestamp ?? '' }}</span>
        </div>
    </div>
</template>
