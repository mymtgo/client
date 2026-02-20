<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

defineProps<{
    won: boolean;
}>();
</script>

<template>
    <Badge :variant="won ? 'success' : 'destructive'">
        {{ won ? 'Win' : 'Loss' }}
    </Badge>
</template>
