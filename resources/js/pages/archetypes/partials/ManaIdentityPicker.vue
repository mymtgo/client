<script setup lang="ts">
import { computed } from 'vue';

const SYMBOLS = [
    { key: 'W', label: 'White', bg: '#F8F6D8', glow: 'rgba(248,246,216,0.5)' },
    { key: 'U', label: 'Blue', bg: '#C1D7E9', glow: 'rgba(125,211,252,0.5)' },
    { key: 'B', label: 'Black', bg: '#BAB1AB', glow: 'rgba(167,139,250,0.5)' },
    { key: 'R', label: 'Red', bg: '#E49977', glow: 'rgba(228,153,119,0.5)' },
    { key: 'G', label: 'Green', bg: '#A3C095', glow: 'rgba(163,192,149,0.5)' },
    { key: 'C', label: 'Colorless', bg: '#ccc2c0', glow: 'rgba(204,194,192,0.5)' },
] as const;

const model = defineModel<string | null>();

const selected = computed(() => new Set(model.value?.split(',').filter(Boolean) ?? []));

function toggle(key: string) {
    const current = new Set(selected.value);
    if (current.has(key)) {
        current.delete(key);
    } else {
        current.add(key);
    }
    const ordered = SYMBOLS.map((s) => s.key).filter((k) => current.has(k));
    model.value = ordered.length ? ordered.join(',') : null;
}
</script>

<template>
    <div>
        <div class="flex gap-2.5">
            <button
                v-for="symbol in SYMBOLS"
                :key="symbol.key"
                type="button"
                class="flex size-9 items-center justify-center rounded-full border-2 text-sm font-bold text-black/80 transition-all"
                :class="selected.has(symbol.key) ? 'opacity-100' : 'opacity-35 border-transparent'"
                :style="{
                    backgroundColor: symbol.bg,
                    borderColor: selected.has(symbol.key) ? symbol.glow : 'transparent',
                    boxShadow: selected.has(symbol.key) ? `0 0 8px ${symbol.glow}` : 'none',
                }"
                :title="symbol.label"
                @click="toggle(symbol.key)"
            >
                {{ symbol.key }}
            </button>
        </div>
    </div>
</template>
