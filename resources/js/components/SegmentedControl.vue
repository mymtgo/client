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
    <div class="flex items-center gap-1">
        <button
            v-for="opt in options"
            :key="opt.value"
            class="relative h-8 cursor-pointer rounded border border-black px-3 text-xs font-medium transition-all"
            :class="
                modelValue === opt.value
                    ? 'border border-black/50 bg-background text-foreground shadow-inner shadow-black/50 outline-[1px] outline-none'
                    : 'bevel border text-muted-foreground hover:text-foreground bg-background hover:brightness-125'
            "
            @click="emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
