<script setup lang="ts">
import { Button, ButtonLink } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { show } from '@/routes/games';
import { ChevronRight, RotateCcw, Trophy, X } from 'lucide-vue-next';
import { computed } from 'vue';
import type { ReplayMatchGame } from './types';

/** Shown on the final frame: the game's result and a way on to the next game of the match. */
const props = defineProps<{
    game: ReplayMatchGame | null;
    next: ReplayMatchGame | null;
    won: boolean | null;
}>();

const emit = defineEmits<{
    restart: [];
    dismiss: [];
}>();

const heading = computed(() => (props.game ? `Game ${props.game.number} complete` : 'Game complete'));

const result = computed(() => {
    if (props.won === null) {
        return { text: 'Result not recorded', class: 'text-muted-foreground' };
    }

    return props.won ? { text: 'You won', class: 'text-yellow-400' } : { text: 'You lost', class: 'text-[#e5484d]' };
});
</script>

<template>
    <div class="texture-bg absolute top-1/2 left-1/2 z-40 w-80 -translate-1/2 rounded-md">
        <Card class="gap-4 px-4 py-4 shadow-[0_10px_30px_rgba(0,0,0,0.45)]">
            <div class="flex items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-semibold">{{ heading }}</span>
                    <span class="flex items-center gap-1.5 text-[12.5px] font-semibold" :class="result.class">
                        <Trophy v-if="won" :size="14" />
                        {{ result.text }}
                    </span>
                </div>
                <button
                    type="button"
                    title="Dismiss"
                    class="grid size-6 cursor-pointer place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="emit('dismiss')"
                >
                    <X :size="14" />
                </button>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="ghost" size="sm" @click="emit('restart')">
                    <RotateCcw />
                    Watch again
                </Button>
                <ButtonLink v-if="next" variant="default" size="sm" :href="show(next.id).url">
                    Watch game {{ next.number }}
                    <ChevronRight />
                </ButtonLink>
            </div>
        </Card>
    </div>
</template>
