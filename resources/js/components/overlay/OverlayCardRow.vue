<script setup lang="ts">
/**
 * One card row in an overlay list (draw odds, revealed cards, sideboard
 * guide): leading bold count, art-crop thumbnail, truncating name, then
 * whatever the caller slots in on the right (draw percentage, community
 * rates). `count: null` renders an empty count cell so mixed lists keep
 * column alignment (sideboard's sided-out rows); omitting it drops the cell.
 * Classes and listeners fall through to the root, so callers keep their own
 * hover handlers and row-state classes (flash highlight, zero-remaining fade).
 */
defineProps<{
    name: string;
    count?: number | null;
    artCrop: string | null;
}>();
</script>

<template>
    <div class="flex items-center text-sm">
        <span v-if="count !== undefined" class="min-w-0 w-8 text-center shrink-0 border-r bg-black/20 px-2 py-1">
            <span class="font-semibold tabular-nums">{{ count }}</span>
        </span>
        <span class="h-7 w-7 shrink-0 overflow-hidden bg-black/20">
            <img v-if="artCrop" :src="artCrop" :alt="name" class="h-full w-full object-cover" />
        </span>
        <span class="min-w-0 grow truncate px-2">
            {{ name }}
        </span>
        <slot />
    </div>
</template>
