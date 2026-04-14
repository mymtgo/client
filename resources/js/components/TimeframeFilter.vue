<script setup lang="ts">
import SegmentedControl from '@/components/SegmentedControl.vue';

defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const options = [
    { value: 'alltime', label: 'All time' },
    { value: 'year', label: 'This year' },
    { value: 'monthly', label: '30 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'week', label: '7 days' },
];
</script>

<template>
    <SegmentedControl :modelValue="modelValue" :options="options" @update:modelValue="emit('update:modelValue', $event)" />
</template>
