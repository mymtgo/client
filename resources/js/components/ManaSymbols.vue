<script lang="ts" setup>
import { computed } from 'vue';
import ManaSymbol from './ManaSymbol.vue';

const props = defineProps<{
    symbols: string | null;
}>();

const symbolsArray = computed(() => props.symbols?.split(',') || []);

const shown = computed(() => {
    const coloured = ['W', 'U', 'B', 'R', 'G'].filter((symbol) => symbolsArray.value.includes(symbol));

    return symbolsArray.value.includes('C') || !symbolsArray.value.length ? [...coloured, 'C'] : coloured;
});
</script>

<template>
    <div class="flex space-x-1">
        <ManaSymbol v-for="symbol in shown" :key="symbol" :symbol="symbol" class="w-4" />
    </div>
</template>
