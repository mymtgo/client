<script setup lang="ts">
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';

/**
 * Wraps any inline element so hovering it floats the full card image.
 * Renders the slot bare when there is no image to show.
 */
withDefaults(
    defineProps<{
        image: string | null | undefined;
        name: string;
        side?: 'top' | 'right' | 'bottom' | 'left';
    }>(),
    { side: 'right' },
);
</script>

<template>
    <HoverCard v-if="image" :open-delay="150" :close-delay="50">
        <HoverCardTrigger as-child>
            <slot />
        </HoverCardTrigger>
        <HoverCardContent :side="side" class="w-auto p-1">
            <img :src="image" :alt="name" class="w-56 rounded-lg" />
        </HoverCardContent>
    </HoverCard>
    <slot v-else />
</template>
