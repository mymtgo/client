<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';

export interface OpponentData {
    username: string;
    previousMatches: number;
    wins: number;
    losses: number;
    lastArchetype: string | null;
    lastArchetypeColors: string | null;
}

export interface LiveArchetypeEstimate {
    archetypeName: string;
    archetypeColorIdentity: string | null;
    confidence: number;
    cardsSeen: number;
}

const props = withDefaults(
    defineProps<{
        opponent: OpponentData | null;
        liveEstimate?: LiveArchetypeEstimate | null;
        font?: string;
        textColor?: string;
    }>(),
    {
        font: 'sans-serif',
        textColor: '#ffffff',
        liveEstimate: null,
    },
);
</script>

<template>
    <div
        class="flex h-full flex-col justify-center px-4 py-3"
        :style="{
            fontFamily: font,
            color: textColor,
        }"
    >
        <template v-if="opponent">
            <div class="flex items-center justify-between text-base">
                <span class="font-semibold">vs {{ opponent.username }}</span>
                <span v-if="opponent.previousMatches > 0" class="tabular-nums" :style="{ color: textColor, opacity: 0.7 }">
                    {{ opponent.wins }}-{{ opponent.losses }}
                </span>
            </div>
            <!-- Live archetype estimate (during match) -->
            <div v-if="liveEstimate" class="mt-0.5 flex items-center gap-1 text-base" :style="{ color: textColor, opacity: 0.7 }">
                <ManaSymbols v-if="liveEstimate.archetypeColorIdentity" :symbols="liveEstimate.archetypeColorIdentity" />
                <span>{{ liveEstimate.archetypeName }}</span>
                <span class="tabular-nums" :style="{ opacity: 0.5 }">{{ liveEstimate.confidence }}%</span>
            </div>
            <!-- Historical archetype (no live estimate yet) -->
            <div v-else-if="opponent?.lastArchetype" class="mt-0.5 flex items-center gap-1 text-base" :style="{ color: textColor, opacity: 0.5 }">
                <ManaSymbols v-if="opponent.lastArchetypeColors" :symbols="opponent.lastArchetypeColors" />
                <span>{{ opponent.lastArchetype }}</span>
            </div>
            <!-- First time opponent -->
            <div v-else-if="opponent?.previousMatches === 0" class="text-base" :style="{ color: textColor, opacity: 0.5 }">
                First time opponent
            </div>
        </template>
    </div>
</template>
