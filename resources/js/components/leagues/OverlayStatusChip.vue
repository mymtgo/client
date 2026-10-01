<script setup lang="ts">
export type ChipTone = 'neutral' | 'amber' | 'green' | 'red' | 'trophy';

defineProps<{ tone: ChipTone }>();
</script>

<template>
    <span
        class="inline-flex min-w-0 items-center gap-1 rounded-full px-2 py-px text-[10px] font-bold tracking-widest whitespace-nowrap uppercase [text-shadow:none]"
        :class="{
            'bg-black/45 text-white/85 backdrop-blur-sm': tone === 'neutral',
            'bg-black/75 text-amber-400 ring-1 ring-amber-400/85 ring-inset': tone === 'amber',
            'bg-black/75 text-emerald-300 ring-1 ring-emerald-400/85 ring-inset': tone === 'green',
            'bg-black/75 text-red-300 ring-1 ring-red-400/85 ring-inset': tone === 'red',
            'border border-transparent text-white [background:linear-gradient(rgb(10_12_16/0.85),rgb(10_12_16/0.85))_padding-box,linear-gradient(135deg,var(--color-pink-400),var(--color-sky-300),var(--color-blue-400))_border-box]':
                tone === 'trophy',
        }"
    >
        <slot />
    </span>
</template>
