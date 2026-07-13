<script setup lang="ts">
import { TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        tone?: 'default' | 'success' | 'destructive' | 'muted';
        delta?: number;
        sub?: string;
    }>(),
    { tone: 'default' },
);

const toneClass = computed(
    () =>
        ({
            default: '',
            success: 'text-success',
            destructive: 'text-destructive',
            muted: 'text-muted-foreground',
        })[props.tone],
);
</script>

<template>
    <div class="flex flex-col items-center gap-1 px-4 py-3">
        <div class="flex items-center gap-1">
            <span class="text-3xl font-bold tabular-nums" :class="toneClass">{{ value }}</span>
            <TrendingUp v-if="delta !== undefined && delta > 0" class="size-4 text-success" />
            <TrendingDown v-if="delta !== undefined && delta < 0" class="size-4 text-destructive" />
        </div>
        <span class="text-xs text-muted-foreground">{{ label }}</span>
        <span v-if="sub" class="text-xs text-muted-foreground/60 tabular-nums">{{ sub }}</span>
    </div>
</template>
