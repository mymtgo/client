<script setup lang="ts">
export type SegmentOption = {
    value: string;
    label: string;
};

defineProps<{
    modelValue: string;
    options: SegmentOption[];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <div class="inline-flex items-center rounded-md border border-border bg-muted p-[3px]">
        <button
            v-for="opt in options"
            :key="opt.value"
            class="cursor-pointer rounded-[7px] px-[15px] py-[5.5px] text-[12.5px] font-medium transition-colors"
            :class="modelValue === opt.value ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground'"
            @click="emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
