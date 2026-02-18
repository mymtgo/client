<script setup lang="ts">
import { computed } from 'vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { XIcon } from 'lucide-vue-next';

const props = defineProps<{
    card: App.Data.Front.CardData;
}>();

const colorIdentityGradients: Record<string, string> = {
    'W': 'from-amber-100 to-amber-200',
    'U': 'from-blue-400 to-blue-600',
    'B': 'from-neutral-800 to-neutral-900',
    'R': 'from-red-600 to-red-800',
    'G': 'from-green-500 to-green-600',
    'C': 'from-slate-600 to-slate-800',
    'G,W': 'from-green-500 to-amber-200',
    'G,U': 'from-green-500 to-blue-500',
    'B,G': 'from-neutral-800 to-green-500',
    'G,R': 'from-green-600 to-red-700',
    'W,U': 'from-amber-100 to-blue-500',
    'U,B': 'from-blue-500 to-neutral-800',
    'B,R': 'from-neutral-800 to-red-700',
    'R,W': 'from-red-600 to-amber-200',
};

const gradientClass = computed(() => {
    return colorIdentityGradients[props.card.identity] ?? 'from-border to-border';
});
</script>

<template>
    <HoverCard>
        <HoverCardTrigger>
            <div class="rounded-md bg-linear-to-l p-0.5" :class="gradientClass">
                <div class="flex items-center justify-between rounded-sm bg-background px-3 py-1.5">
                    <div class="flex min-w-0 flex-1 items-center gap-1 text-sm">
                        <span class="flex shrink-0 items-center gap-0.5 text-muted-foreground">{{ card.quantity }}<XIcon :size="10" /></span>
                        <span class="truncate">{{ card.name }}</span>
                    </div>
                    <ManaSymbols :symbols="card.identity" />
                </div>
            </div>
        </HoverCardTrigger>
        <HoverCardContent side="right" avoidCollisions class="overflow-hidden rounded-xl p-0">
            <img :src="card.image" class="w-64" />
        </HoverCardContent>
    </HoverCard>
</template>
