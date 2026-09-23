<script setup lang="ts">
import ReplayCardImage from './ReplayCardImage.vue';
import { useReplayContext } from './replayContext';
import type { ReplayCard } from './types';

defineProps<{
    cards: ReplayCard[];
    count: number;
}>();

const { showPreview, hidePreview } = useReplayContext();
</script>

<template>
    <div class="order-3 flex-none px-2 pb-2">
        <div class="nav-item-active box-border flex h-25 items-center gap-2.5 rounded-md px-3 py-2">
            <div class="w-8.5 flex-none text-[11px] leading-tight text-muted-foreground">Hand<br />{{ count }}</div>
            <div class="flex h-full min-w-0 flex-1 gap-1">
                <div
                    v-for="(card, i) in cards"
                    :key="card.Id"
                    class="relative aspect-[63/88] h-full min-w-[18px] transition-transform duration-150 hover:z-20 hover:-translate-y-1"
                    :style="{ flex: `0 ${i === cards.length - 1 ? 0 : 1} auto` }"
                    @mouseenter="showPreview($event, card)"
                    @mouseleave="hidePreview"
                >
                    <div class="absolute top-0 left-0 aspect-[63/88] h-full rounded shadow-[0_1px_4px_rgba(0,0,0,.35)]">
                        <ReplayCardImage :name="card.name" :image="card.image" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
