<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    /** Plain-text label. Use the `label` slot instead when it needs markup. */
    label?: string;
    rate: number;
    /** Wins and losses behind the rate, shown beside it. */
    record?: string | null;
    tone: 'success' | 'destructive' | 'muted';
}>();

const width = computed(() => Math.max(0, Math.min(100, props.rate)));
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <span class="min-w-0 text-sm">
                <slot name="label">{{ label }}</slot>
            </span>
            <span class="shrink-0 text-sm tabular-nums">
                <span class="font-semibold">{{ rate }}%</span>
                <span v-if="record" class="ml-1.5 text-muted-foreground">{{ record }}</span>
            </span>
        </div>
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full transition-[width]"
                :class="{
                    'bg-success': tone === 'success',
                    'bg-destructive': tone === 'destructive',
                    'bg-muted-foreground': tone === 'muted',
                }"
                :style="{ width: `${width}%` }"
            />
        </div>
    </div>
</template>
