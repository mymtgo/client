<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { computed } from 'vue';
import ReplayCardImage from './ReplayCardImage.vue';
import { useReplayContext } from './replayContext';
import type { ReplayCard } from './types';

/** Stack items, top of the stack first. */
const props = defineProps<{
    items: ReplayCard[];
    playerName: (id: number | undefined) => string;
}>();

const { showPreview, hidePreview } = useReplayContext();

/** Bottom of the stack on the left, so the next object to resolve sits rightmost and on top. */
const cards = computed(() => props.items.toReversed());

function describe(item: ReplayCard, index: number): string {
    const owner = props.playerName(item.Controller ?? item.Owner);
    const next = index === cards.value.length - 1 ? ' · resolves next' : '';

    return `${item.name ?? 'Unknown card'} (${owner})${next}`;
}
</script>

<template>
    <!-- Opaque backing so the board never shows through the floating card. -->
    <div class="texture-bg relative z-30 flex-none rounded-md">
        <Card class="gap-2 px-3 py-2.5 shadow-[0_10px_30px_rgba(0,0,0,0.45)]">
            <div class="flex items-baseline gap-1.5 text-[11px] leading-tight">
                <span class="font-semibold">Stack</span>
                <span class="text-muted-foreground tabular-nums">{{ items.length }}</span>
            </div>
            <TransitionGroup
                tag="div"
                class="flex items-center"
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-x-3 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="-translate-y-2 opacity-0"
            >
                <div
                    v-for="(item, i) in cards"
                    :key="item.Id"
                    :title="describe(item, i)"
                    class="relative aspect-[63/88] w-24 flex-none rounded shadow-[0_4px_12px_rgba(0,0,0,0.55)] transition-transform duration-150 not-first:-ms-14 hover:z-40 hover:-translate-y-1.5"
                    @mouseenter="showPreview($event, item)"
                    @mouseleave="hidePreview"
                >
                    <ReplayCardImage :name="item.name" :type="item.type" :image="item.image" />
                </div>
            </TransitionGroup>
        </Card>
    </div>
</template>
