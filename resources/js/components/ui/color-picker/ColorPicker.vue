<script setup lang="ts">
import { ColorPickerRoot, ColorPickerCanvas, ColorPickerSliderHue, ColorPickerInputHex } from '@vuelor/picker';
import '@vuelor/picker/style.css';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const color = ref(props.modelValue);

watch(
    () => props.modelValue,
    (val) => {
        color.value = val;
    },
);

watch(color, (val) => {
    if (val && val !== props.modelValue) {
        emit('update:modelValue', val);
    }
});
</script>

<template>
    <ColorPickerRoot v-model="color" class="flex flex-col gap-2">
        <ColorPickerCanvas class="h-28 w-44 rounded-md border" />
        <ColorPickerSliderHue class="h-3 w-44 rounded-full" />
        <ColorPickerInputHex class="w-44 rounded-md border bg-transparent px-2 py-1 text-xs" />
    </ColorPickerRoot>
</template>
