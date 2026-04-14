<script setup lang="ts">
defineProps<{
    winrate: number;
    size?: 'sm' | 'default';
    solid?: boolean;
}>();
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            class="flex-1 overflow-hidden rounded-full border border-black/40 shadow-inner shadow-black/30"
            :class="[size === 'sm' ? 'h-1.5' : 'h-2', solid ? 'bg-black/60' : 'bg-black/10']"
        >
            <div
                class="bevel h-full rounded-full bg-success shadow shadow-black/50 transition-[width] duration-300 ease-out"
                :style="{ width: `${Math.max(0, Math.min(100, winrate))}%` }"
            />
        </div>
        <span
            class="shrink-0 font-semibold tabular-nums"
            :class="[size === 'sm' ? 'text-xs' : 'text-sm', winrate < 50 ? 'text-destructive' : winrate === 50 ? 'text-muted-foreground' : '']"
        >
            {{ winrate }}%
        </span>
    </div>
</template>
