<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        data-slot="card"
        :class="
            cn(
                'flex flex-col gap-6 rounded-md border border-white/5 bg-black/40 py-6 text-card-foreground shadow outline-1 outline-black/60',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
