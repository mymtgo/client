<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { COLOR_NAMES, COLOR_ORDER, type ManaColor } from '@/types/limited';
import { computed } from 'vue';

const props = defineProps<{ colors: string; size?: 'sm' | 'md' }>();

/** Colours present, in WUBRG order; colourless when none. Rendered with the real mana symbols. */
const pips = computed<(ManaColor | 'C')[]>(() => {
    const present = COLOR_ORDER.filter((c) => props.colors.includes(c));
    return present.length ? present : ['C'];
});

const symbols = computed(() => pips.value.join(','));

const label = computed(() => pips.value.map((pip) => (pip === 'C' ? 'Colorless' : COLOR_NAMES[pip])).join(', '));
</script>

<template>
    <span class="inline-flex items-center" role="img" :aria-label="label">
        <ManaSymbols :symbols="symbols" :class="size === 'md' ? '' : 'origin-left scale-75'" />
    </span>
</template>
