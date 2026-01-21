<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div class="rounded-2xl border border-black/50 bg-sidebar px-1 py-1">
        <div
            data-slot="card"
            :class="cn('flex flex-col gap-6 rounded-xl border border-background bg-card py-6 text-card-foreground shadow', props.class)"
        >
            <slot />
        </div>
    </div>
</template>
