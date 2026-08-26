<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { StateVariant } from '@/types/limited';
import { computed } from 'vue';

const props = defineProps<{ label: string; variant: StateVariant | string }>();

const badgeVariant = computed(() => {
    switch (props.variant) {
        case 'success':
        case 'destructive':
        case 'secondary':
            return props.variant;
        case 'warning':
            return 'outline';
        default:
            return 'default';
    }
});
</script>

<template>
    <Badge :variant="badgeVariant" :class="variant === 'warning' ? 'border-amber-500/40 text-amber-300' : ''">
        {{ label }}
    </Badge>
</template>
