<script setup lang="ts">
import { ArrowDownToLine, CircleMinus, CirclePlus, Sparkle } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

/** Small inset pill shown under a card tile to mark what happened to it. */
type Tone = 'in' | 'out' | 'bottomed' | 'draw';

const props = defineProps<{
    tone: Tone;
}>();

const icons: Record<Tone, { component: Component; class: string }> = {
    in: { component: CirclePlus, class: 'text-success' },
    out: { component: CircleMinus, class: 'text-destructive' },
    bottomed: { component: ArrowDownToLine, class: 'text-destructive' },
    draw: { component: Sparkle, class: 'text-sky-400' },
};

const icon = computed(() => icons[props.tone]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full bg-black/10 px-2 py-0.5 font-mono text-[10px] font-medium text-muted-foreground tabular-nums shadow-[inset_0_1px_2px_rgba(0,0,0,0.25)] ring-1 ring-black/10 dark:bg-black/40 dark:shadow-[inset_0_1px_2px_rgba(0,0,0,0.6),0_1px_0_rgba(255,255,255,0.04)] dark:ring-white/5"
    >
        <component :is="icon.component" :size="10" :class="icon.class" class="shrink-0" />
        <slot />
    </span>
</template>
