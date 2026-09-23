<script setup lang="ts">
import ReplayCardImage from './ReplayCardImage.vue';
import type { CardPreviewPosition, ReplayCard } from './types';

defineProps<{
    card: ReplayCard;
    chips: string[];
    position: CardPreviewPosition;
}>();
</script>

<template>
    <div
        class="pointer-events-none absolute z-90 flex flex-col gap-1.5"
        :style="{ left: `${position.left}px`, top: `${position.top}px`, width: `${position.width}px` }"
    >
        <div class="relative aspect-[63/88] w-full rounded-[4.5%/3.3%] shadow-[0_14px_40px_rgba(0,0,0,.55)]">
            <ReplayCardImage :name="card.name" :type="card.type" :image="card.image" show-type />
        </div>
        <div v-if="chips.length" class="flex flex-wrap gap-1">
            <span
                v-for="chip in chips"
                :key="chip"
                class="rounded-[5px] border border-border bg-card px-1.75 py-0.75 text-[11.5px] font-semibold shadow-[0_2px_8px_rgba(0,0,0,.25)]"
            >
                {{ chip }}
            </span>
        </div>
    </div>
</template>
