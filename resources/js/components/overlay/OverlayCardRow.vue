<script setup lang="ts">
/**
 * One card row in an overlay list (draw odds, revealed cards, sideboard
 * guide): leading bold count, art-crop thumbnail, truncating name, then
 * whatever the caller slots in on the right (draw percentage, community
 * rates). `count: null` renders an empty count cell so mixed lists keep
 * column alignment (sideboard's sided-out rows); omitting it drops the cell.
 * Classes and listeners fall through to the root, so callers keep their own
 * row-state classes (flash highlight, zero-remaining fade). The card image
 * preview is driven from the thumbnail alone: tied to the whole row it popped
 * up over the list whenever the pointer crossed a row while scrolling.
 */
defineProps<{
    name: string;
    count?: number | null;
    artCrop: string | null;
}>();

const emit = defineEmits<{
    previewEnter: [event: MouseEvent];
    previewLeave: [];
}>();
</script>

<template>
    <div class="flex items-center text-sm">
        <span v-if="count !== undefined" class="w-8 min-w-0 shrink-0 border-r bg-black/20 px-2 py-1 text-center">
            <span class="font-semibold tabular-nums">{{ count }}</span>
        </span>
        <span
            class="h-7 w-7 shrink-0 cursor-zoom-in overflow-hidden bg-black/20 transition-[filter] hover:brightness-125"
            @mouseenter="emit('previewEnter', $event)"
            @mouseleave="emit('previewLeave')"
        >
            <img v-if="artCrop" :src="artCrop" :alt="name" class="h-full w-full object-cover" />
        </span>
        <span class="min-w-0 grow truncate px-2">
            {{ name }}
        </span>
        <slot />
    </div>
</template>
