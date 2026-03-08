<script setup lang="ts">
const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

function onInput(event: Event) {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
}
</script>

<template>
    <input
        type="color"
        :value="modelValue"
        @input="onInput"
        class="h-8 w-12 cursor-pointer rounded border border-border bg-transparent p-0.5"
    />
</template>
