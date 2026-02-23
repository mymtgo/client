<script setup lang="ts">
import { Button } from '@/components/ui/button'

defineProps<{
    isPlaying: boolean
    isAtStart: boolean
    isAtEnd: boolean
    canPlay: boolean
    currentTimestamp: string
    currentIndex: number
    totalEvents: number
    playbackSpeed: number
}>()

const emit = defineEmits<{
    togglePlay: []
    stepPrev: []
    stepNext: []
    setSpeed: [speed: number]
}>()

const speeds = [0.5, 1, 1.5, 2, 4]
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-4 border bg-card p-3">
        <!-- Playback controls -->
        <div class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="isAtStart"
                @click="emit('stepPrev')"
                aria-label="Previous event"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="19 20 9 12 19 4 19 20" />
                    <line x1="5" y1="19" x2="5" y2="5" />
                </svg>
            </Button>

            <Button
                variant="default"
                size="sm"
                :disabled="!canPlay || isAtEnd"
                @click="emit('togglePlay')"
                :aria-label="isPlaying ? 'Pause' : 'Play'"
            >
                <!-- Pause icon -->
                <svg v-if="isPlaying" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="6" y="4" width="4" height="16" />
                    <rect x="14" y="4" width="4" height="16" />
                </svg>
                <!-- Play icon -->
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3" />
                </svg>
            </Button>

            <Button
                variant="outline"
                size="sm"
                :disabled="isAtEnd"
                @click="emit('stepNext')"
                aria-label="Next event"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 4 15 12 5 20 5 4" />
                    <line x1="19" y1="5" x2="19" y2="19" />
                </svg>
            </Button>
        </div>

        <!-- Timestamp and progress -->
        <div class="flex items-center gap-3 text-sm">
            <span class="font-mono font-medium">{{ currentTimestamp }}</span>
            <span class="text-muted-foreground">
                {{ currentIndex + 1 }} / {{ totalEvents }}
            </span>
        </div>

        <!-- Speed control -->
        <div class="flex items-center gap-2">
            <span class="text-xs text-muted-foreground">Speed:</span>
            <div class="flex gap-1">
                <Button
                    v-for="speed in speeds"
                    :key="speed"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    :class="{ 'bg-accent': playbackSpeed === speed }"
                    @click="emit('setSpeed', speed)"
                >
                    {{ speed }}x
                </Button>
            </div>
        </div>
    </div>
</template>
