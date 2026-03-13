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

const props = withDefaults(
    defineProps<{
        opponent: OpponentData | null;
        font?: string;
        textColor?: string;
    }>(),
    {
        font: 'sans-serif',
        textColor: '#ffffff',
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
            <div v-if="opponent.lastArchetype" class="mt-0.5 flex items-center gap-1 text-base" :style="{ color: textColor, opacity: 0.5 }">
                <ManaSymbols v-if="opponent.lastArchetypeColors" :symbols="opponent.lastArchetypeColors" />
                <span>{{ opponent.lastArchetype }}</span>
            </div>
            <div v-else-if="opponent.previousMatches === 0" class="text-base" :style="{ color: textColor, opacity: 0.5 }">
                First time opponent
            </div>
        </template>
    </div>
</template>
