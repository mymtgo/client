<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { StateVariant } from '@/types/limited';
import { computed } from 'vue';

const props = defineProps<{ label: string; variant: StateVariant | string }>();

/**
 * State badges sit in a row of plain outline chips (set code, kind, record),
 * so they tint an outline rather than fill it: a solid slab reads as the
 * loudest thing on the page for the least useful fact. Only a live run gets
 * an extra signal, the pulsing dot.
 */
const tint = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'border-emerald-500/30 text-emerald-300';
        case 'destructive':
            return 'border-rose-500/30 text-rose-300';
        case 'warning':
            return 'border-amber-500/30 text-amber-300';
        case 'default':
            return 'border-sky-400/30 text-sky-300';
        default:
            return '';
    }
});

const isLive = computed(() => props.variant === 'default');
</script>

<template>
    <Badge variant="outline" :class="tint">
        <span v-if="isLive" class="size-1.5 animate-pulse rounded-full bg-sky-400" />
        {{ label }}
    </Badge>
</template>
