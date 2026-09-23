<script setup lang="ts">
import { show } from '@/routes/games';
import { Link } from '@inertiajs/vue3';
import type { ReplayMatchGame } from './types';

/** Jumps between the games of the match. Results stay hidden so no game is spoiled. */
defineProps<{
    games: ReplayMatchGame[];
    gameId: number;
}>();
</script>

<template>
    <nav aria-label="Games in this match" class="flex flex-none items-center gap-0.5 rounded-lg bg-background p-0.5">
        <Link
            v-for="game in games"
            :key="game.id"
            :href="show(game.id).url"
            :title="`Game ${game.number}`"
            :aria-current="game.id === gameId ? 'page' : undefined"
            class="grid h-6.5 min-w-9 place-items-center rounded-md px-1.5 text-xs font-semibold tabular-nums"
            :class="game.id === gameId ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground'"
        >
            G{{ game.number }}
        </Link>
    </nav>
</template>
