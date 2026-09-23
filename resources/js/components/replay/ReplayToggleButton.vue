<script setup lang="ts">
import type { Component } from 'vue';

/** Main-nav style button: raised when off, pressed in with a glowing icon while its panel is open. */
defineProps<{
    active: boolean;
    icon: Component;
}>();
</script>

<template>
    <button
        type="button"
        class="relative inline-flex h-7 flex-none cursor-pointer items-center gap-1.5 rounded-md border px-2.5 text-[12.5px] font-medium transition-colors"
        :class="
            active
                ? 'border-black shadow-inner shadow-black outline-[1px] outline-white/10'
                : 'bevel border-black/60 hover:bg-accent/50 hover:text-accent-foreground'
        "
    >
        <component :is="icon" :size="14" :class="active ? 'nav-icon-active' : 'text-muted-foreground'" />
        <slot />
    </button>
</template>
