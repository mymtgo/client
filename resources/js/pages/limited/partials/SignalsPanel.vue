<script setup lang="ts">
import ManaPips from '@/components/limited/ManaPips.vue';
import { COLOR_NAMES, type DraftSignal } from '@/types/limited';

defineProps<{ signals: DraftSignal[] }>();
</script>

<template>
    <div class="flex flex-col gap-4 rounded-lg border border-black/60 bg-card p-4">
        <div class="flex flex-col">
            <h2 class="text-sm font-semibold">Signals</h2>
            <span class="text-xs text-muted-foreground">what came back to you</span>
        </div>
        <div class="flex flex-col gap-3">
            <div v-for="signal in signals" :key="signal.color" class="flex flex-col gap-1.5">
                <div class="flex items-center gap-2 text-xs">
                    <ManaPips :colors="signal.color" size="md" />
                    <span class="font-medium">{{ COLOR_NAMES[signal.color] }}</span>
                    <span class="ml-auto text-muted-foreground tabular-nums">{{ signal.wheeled }} wheeled · {{ signal.seen_twice }} seen 2+</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary" :style="{ width: `${Math.max(4, signal.share * 100)}%` }" />
                </div>
            </div>
        </div>
    </div>
</template>
