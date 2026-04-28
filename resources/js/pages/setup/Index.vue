<script setup lang="ts">
import StepArchetypes from '@/pages/setup/partials/StepArchetypes.vue';
import StepDecks from '@/pages/setup/partials/StepDecks.vue';
import StepPaths from '@/pages/setup/partials/StepPaths.vue';
import { type PathStatus } from '@/composables/useLogPathSync';
import { ref } from 'vue';

const props = defineProps<{
    logPath: string;
    dataPath: string;
    logPathStatus: PathStatus;
    dataPathStatus: PathStatus;
    archetypeCount: number;
    deckCount: number;
    setupSkippedArchetypes: boolean;
    setupSkippedDecks: boolean;
}>();

const step = ref<'paths' | 'archetypes' | 'decks' | 'finish'>('paths');
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-background p-8">
        <div class="w-full max-w-xl rounded-lg border bg-card p-8 shadow-sm">
            <div class="mb-6 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                <span>Step</span>
                <span>{{ ['paths', 'archetypes', 'decks', 'finish'].indexOf(step) + 1 }} of 4</span>
            </div>

            <StepPaths
                v-if="step === 'paths'"
                :log-path="props.logPath"
                :data-path="props.dataPath"
                :log-path-status="props.logPathStatus"
                :data-path-status="props.dataPathStatus"
                @continue="step = 'archetypes'"
            />

            <StepArchetypes
                v-else-if="step === 'archetypes'"
                :archetype-count="props.archetypeCount"
                :skipped="props.setupSkippedArchetypes"
                @continue="step = 'decks'"
            />

            <StepDecks
                v-else-if="step === 'decks'"
                :deck-count="props.deckCount"
                :skipped="props.setupSkippedDecks"
                @continue="step = 'finish'"
            />
        </div>
    </div>
</template>
