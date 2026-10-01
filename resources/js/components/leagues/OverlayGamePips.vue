<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    games: Array<{ won: boolean | null }>;
    /** True only while a game is being played (status in_game). */
    live: boolean;
}>();

/**
 * Real games come from the backend; pad to best-of-three, or further when a
 * draw pushed the match past three games. The live pip is the last game when
 * its result is not known yet.
 */
const pips = computed(() => {
    const total = Math.max(3, props.games.length);

    // With no game row yet (deck submission before game 1), game 1 is the live one.
    const liveIndex = Math.max(0, props.games.length - 1);

    return Array.from({ length: total }, (_, i) => {
        const game = props.games[i];
        const isLive = props.live && i === liveIndex && (game?.won ?? null) === null;

        return { key: i, won: game?.won ?? null, live: isLive };
    });
});
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <span
            v-for="pip in pips"
            :key="pip.key"
            class="block size-2 rounded-full ring-1 ring-black/60"
            :class="{
                'bevel bg-success': pip.won === true,
                'bevel bg-destructive': pip.won === false,
                'animate-pulse bg-white': pip.live,
                'bg-white/20': pip.won === null && !pip.live,
            }"
        />
    </span>
</template>
