<script setup lang="ts">
defineProps<{
    modelValue: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();
</script>

<template>
    <button
        type="button"
        class="inline-flex cursor-pointer items-center gap-[7px] rounded-full border px-[13px] py-[5px] text-[12.5px] font-medium transition-colors"
        :class="modelValue ? 'border-primary bg-primary-soft text-primary-hi' : 'border-line-2 text-muted-foreground hover:text-foreground'"
        @click="emit('update:modelValue', !modelValue)"
    >
        <slot />
    </button>
</template>
