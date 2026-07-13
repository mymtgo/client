<script setup lang="ts">
import { computed } from 'vue';

/**
 * The one sanctioned Badge specialisation — W–L records only (v2).
 * `perfect` (trophy 5–0) carries the system's one sanctioned gradient.
 */
const props = withDefaults(
    defineProps<{
        tone?: 'default' | 'hot' | 'perfect' | 'cold' | 'live';
    }>(),
    { tone: 'default' },
);

const toneClass = computed(
    () =>
        ({
            default: 'border-line-2 text-foreground',
            hot: 'border-win text-win',
            cold: 'border-loss text-loss',
            live: 'border-primary text-primary-hi',
            perfect:
                'border-[1.5px] border-transparent text-foreground [background:linear-gradient(var(--card),var(--card))_padding-box,linear-gradient(120deg,#3b82f6,#e838c8)_border-box] shadow-[0_0_12px_rgba(146,93,224,0.25)]',
        })[props.tone],
);
</script>

<template>
    <span class="inline-flex items-center rounded-[7px] border px-2.5 py-[3px] font-mono text-xs font-semibold" :class="toneClass">
        <slot />
    </span>
</template>
