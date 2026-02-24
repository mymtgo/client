<script setup lang="ts">
defineProps<{
    won: boolean;
    showText?: boolean;
}>();
</script>

<template>
    <div
        class="text-sm flex items-center gap-2 font-medium"
        :class="{
            'text-success': won,
            'text-destructive': !won,
        }"
    >
        <div
            class="bevel block h-2 w-2 rounded-full"
            :class="{
                'bg-success': won,
                'bg-destructive': !won,
            }"
        ></div>
        <div :class="{
            'sr-only': !showText
        }">{{ won ? 'Win' : 'Loss' }}</div>
    </div>
</template>
