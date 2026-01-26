<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { XIcon } from 'lucide-vue-next';

defineProps<{
    card: App.Data.Front.CardData;
}>();
</script>

<template>
    <div>
        <HoverCard>
            <HoverCardTrigger>
                <div
                    class="rounded-md bg-linear-to-l p-0.5"
                    :class="{
                        'bg-green-500': card.identity == 'G',
                        'from-slate-600 to-slate-800': card.identity == 'C',
                        'from-neutral-800 to-neutral-900': card.identity == 'B',
                        'from-green-500 to-white': card.identity == 'G,W',
                        'from-green-500 to-blue-500': card.identity == 'G,U',
                        'from-neutral-800 to-green-500': card.identity == 'B,G',
                        'from-green-600 to-red-700': card.identity == 'G,R',
                    }"
                >
                    <div class="flex items-center justify-between rounded bg-background px-3 py-2">
                        <div class="flex w-[90%] items-center gap-1 text-sm">
                            <div class="flex items-center gap-1">{{ card.quantity }} <XIcon :size="12" /></div>
                            <div class="truncate">{{ card.name }}</div>
                        </div>
                        <div>
                            <ManaSymbols :symbols="card.identity" />
                        </div>
                    </div>
                </div>
            </HoverCardTrigger>
            <HoverCardContent side="right" avoidCollisions class="overflow-hidden rounded-xl p-0">
                <img :src="card.image" class="w-64" />
            </HoverCardContent>
        </HoverCard>
    </div>
</template>

<style scoped></style>
