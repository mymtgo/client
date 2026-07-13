<script setup lang="ts">
import { computed } from 'vue';
import { winrateTone } from '@/lib/stats/winrate';

const props = defineProps<{
    winrate: number;
    size?: 'sm' | 'default';
}>();

const tone = computed(() => winrateTone(props.winrate));

const fillClass = computed(() => ({ win: 'bg-win', muted: 'bg-muted-foreground', loss: 'bg-loss' })[tone.value]);
const textClass = computed(() => ({ win: 'text-win', muted: 'text-muted-foreground', loss: 'text-loss' })[tone.value]);
</script>

<template>
    <div class="flex items-center gap-3.5">
        <div class="flex-1 overflow-hidden rounded-full bg-secondary" :class="size === 'sm' ? 'h-1.5' : 'h-2'">
            <div
                class="h-full rounded-full transition-[width] duration-300 ease-out"
                :class="fillClass"
                :style="{ width: `${Math.max(0, Math.min(100, winrate))}%` }"
            />
        </div>
        <span class="shrink-0 font-mono font-semibold tabular-nums" :class="[size === 'sm' ? 'text-xs' : 'text-[13px]', textClass]">
            {{ winrate }}%
        </span>
    </div>
</template>
