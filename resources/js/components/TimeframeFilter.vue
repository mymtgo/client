<script setup lang="ts">
import SegmentedControl from '@/components/SegmentedControl.vue';
import { TIMEFRAME_OPTIONS } from '@/lib/timeframes';

defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <SegmentedControl :modelValue="modelValue" :options="TIMEFRAME_OPTIONS" @update:modelValue="emit('update:modelValue', $event)" />
</template>
