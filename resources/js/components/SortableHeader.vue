<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';

defineProps<{
    label: string;
    column: string;
    sortBy?: string | null;
    sortDir?: 'asc' | 'desc';
}>();
</script>

<template>
    <span class="flex items-center gap-1">
        {{ label }}
        <ArrowDown v-if="sortBy === column && sortDir === 'desc'" class="size-3" />
        <ArrowUp v-else-if="sortBy === column && sortDir === 'asc'" class="size-3" />
        <ArrowUpDown v-else class="size-3 opacity-30" />
    </span>
</template>
