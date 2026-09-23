<script setup lang="ts">
import type { ScrubTooltipView } from './types';

defineProps<{
    tooltip: ScrubTooltipView;
}>();
</script>

<template>
    <div
        class="pointer-events-none absolute z-50 flex w-60 flex-col gap-0.75 rounded-lg border border-border bg-card px-2.5 py-2 shadow-[0_10px_30px_rgba(0,0,0,.35)]"
        :style="{ left: `${tooltip.left}px`, bottom: `${tooltip.bottom}px` }"
    >
        <div class="flex justify-between gap-2">
            <span class="text-xs font-semibold">{{ tooltip.title }}</span>
            <span class="font-mono text-[11px] text-muted-foreground">{{ tooltip.time }}</span>
        </div>
        <span class="text-xs text-pretty text-muted-foreground">{{ tooltip.sub }}</span>
    </div>
</template>
