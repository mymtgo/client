<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const timeframes = [
    { value: 'alltime', label: 'All time' },
    { value: 'year', label: 'This year' },
    { value: 'monthly', label: '30 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'week', label: '7 days' },
];
</script>

<template>
    <div class="flex items-center gap-1 rounded-md border p-1">
        <Button
            v-for="tf in timeframes"
            :key="tf.value"
            size="sm"
            :variant="modelValue === tf.value ? 'default' : 'ghost'"
            class="h-7 px-3 text-xs"
            @click="emit('update:modelValue', tf.value)"
        >
            {{ tf.label }}
        </Button>
    </div>
</template>
