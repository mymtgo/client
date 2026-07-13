<script setup lang="ts">
import SinkSection from './SinkSection.vue';

const colorTokens = [
    'background',
    'card',
    'muted',
    'secondary',
    'foreground',
    'muted-foreground',
    'dim',
    'primary',
    'primary-hi',
    'win',
    'loss',
    'warn',
    'destructive',
    'border',
    'line-2',
    'chart-1',
    'chart-2',
];

/** v2 assignments: sm = menu items, chips · md = buttons, inputs, triggers, overlays, nav items · lg = cards · xl = page panels · full = badges, filter chips. */
const radii = [
    { label: 'sm 6', class: 'rounded-sm' },
    { label: 'md 10', class: 'rounded-md' },
    { label: 'lg 14', class: 'rounded-lg' },
    { label: 'xl 18', class: 'rounded-xl' },
    { label: 'full', class: 'rounded-full' },
];
</script>

<template>
    <SinkSection id="theme" title="Theme">
        <div>
            <h3 class="mb-3 text-sm font-medium text-muted-foreground">Color tokens</h3>
            <div class="flex flex-wrap gap-4">
                <div v-for="token in colorTokens" :key="token" class="flex flex-col items-center gap-1.5">
                    <div class="size-10 rounded-md border" :style="{ background: `var(--${token})` }" />
                    <span class="text-xs text-muted-foreground">{{ token }}</span>
                </div>
            </div>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-medium text-muted-foreground">Radius scale</h3>
            <div class="flex flex-wrap items-end gap-4">
                <div v-for="radius in radii" :key="radius.label" class="flex flex-col items-center gap-1.5">
                    <div class="size-14 border border-line-2 bg-secondary" :class="radius.class" />
                    <span class="font-mono text-[10px] text-dim">{{ radius.label }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-4">
            <div class="flex flex-col gap-1.5">
                <div class="bevel flex h-20 w-40 items-center justify-center rounded-md border border-black/60 bg-neutral-800 text-xs text-white">
                    .bevel
                </div>
                <span class="text-xs text-muted-foreground">Bevel highlight/shadow treatment</span>
            </div>
            <div class="flex flex-col gap-1.5">
                <div class="texture-bg flex h-20 w-40 items-center justify-center rounded-md border text-xs">.texture-bg</div>
                <span class="text-xs text-muted-foreground">Background texture overlay</span>
            </div>
        </div>
    </SinkSection>
</template>
