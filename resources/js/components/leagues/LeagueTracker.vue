<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue';

export interface LeagueData {
    id: number;
    name: string;
    format: string;
    wins: number;
    losses: number;
    totalMatches: number;
    deckId: number | null;
    deckName: string | null;
    backgroundUrl: string | null;
    hasActiveMatch: boolean;
    games: Array<{ won: boolean | null; ended: boolean }>;
}

const props = withDefaults(
    defineProps<{
        league: LeagueData | null;
        font?: string;
        textColor?: string;
    }>(),
    {
        font: 'sans-serif',
        textColor: '#ffffff',
    },
);

const labelInput = useTemplateRef<HTMLInputElement>('labelInput');
const labelStorageKey = computed(() => (props.league?.deckId ? `league-overlay-label:${props.league.deckId}` : null));
const customLabel = ref('');

function blurLabel() {
    labelInput.value?.blur();
}

function onBackdropMousedown(event: MouseEvent) {
    if (!labelInput.value) return;
    if (event.target === labelInput.value) return;
    blurLabel();
}

watch(
    labelStorageKey,
    (key) => {
        if (!key || typeof window === 'undefined') {
            customLabel.value = '';
            return;
        }
        customLabel.value = window.localStorage.getItem(key) ?? '';
    },
    { immediate: true },
);

function onLabelInput(event: Event) {
    const value = (event.target as HTMLInputElement).value;
    customLabel.value = value;
    const key = labelStorageKey.value;
    if (!key || typeof window === 'undefined') {
        return;
    }
    if (value.trim() === '') {
        window.localStorage.removeItem(key);
    } else {
        window.localStorage.setItem(key, value);
    }
}

function gameDotClass(game: { won: boolean | null; ended: boolean } | undefined) {
    if (!game) return 'border-2 border-white/20 bg-transparent';
    if (game.won === true) return 'bg-green-500';
    if (game.won === false) return 'bg-red-500';

    if (!game.ended) return 'animate-pulse border-2 border-white/70 bg-transparent';

    return 'border-2 border-white/40 bg-transparent';
}
</script>

<template>
    <div
        class="relative h-full overflow-hidden"
        :style="{ fontFamily: font, color: textColor, textShadow: '0 1px 2px rgba(0,0,0,0.9), 0 0 6px rgba(0,0,0,0.6)' }"
        @mousedown="onBackdropMousedown"
    >
        <div
            v-if="league?.backgroundUrl"
            class="absolute inset-0 bg-cover bg-top"
            :style="{ backgroundImage: `url(${league.backgroundUrl})` }"
        />
        <div class="absolute inset-0 bg-black/55" />

        <div class="relative flex h-full flex-col justify-center px-3 py-2">
            <template v-if="league">
                <div class="flex items-baseline justify-between gap-2">
                    <input
                        ref="labelInput"
                        type="text"
                        :value="customLabel"
                        :placeholder="league.deckName ?? 'Unknown Deck'"
                        spellcheck="false"
                        class="min-w-0 flex-1 truncate border-0 bg-transparent text-lg font-semibold outline-none placeholder:text-white placeholder:opacity-100 focus:bg-white/10 focus:rounded focus:px-1"
                        style="-webkit-app-region: no-drag"
                        @input="onLabelInput"
                        @keydown.enter.prevent="blurLabel"
                        @keydown.escape.prevent="blurLabel"
                    />
                    <span class="shrink-0 text-2xl font-bold tabular-nums">
                        {{ league.wins }}-{{ league.losses }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-sm" :style="{ color: textColor, opacity: 0.8 }">
                    <span class="inline-flex items-center gap-1">
                        {{ league.format }}
                    </span>
                    <span v-if="league.hasActiveMatch" class="inline-flex items-center gap-1">
                        <span
                            v-for="i in 3"
                            :key="i"
                            class="inline-block size-2.5 rounded-full"
                            :class="gameDotClass(league.games[i - 1])"
                        />
                    </span>
                </div>
            </template>
            <template v-else>
                <p class="text-center text-sm" :style="{ color: textColor, opacity: 0.7 }">
                    Start or continue a league to track
                </p>
            </template>

            <span
                class="pointer-events-none absolute bottom-0.5 right-2 text-[9px] font-medium tracking-widest uppercase opacity-50"
                :style="{ color: textColor }"
            >
                mymtgo.com
            </span>
        </div>
    </div>
</template>
