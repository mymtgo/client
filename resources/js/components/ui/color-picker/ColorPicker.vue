<script setup lang="ts">
const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    change: [value: string];
}>();

function onInput(event: Event) {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
}

function onChange(event: Event) {
    const target = event.target as HTMLInputElement;
    emit('change', target.value);
}
</script>

<template>
    <input
        type="color"
        :value="modelValue"
        @input="onInput"
        @change="onChange"
        class="h-8 w-12 cursor-pointer rounded border border-border bg-transparent p-0.5"
    />
</template>
