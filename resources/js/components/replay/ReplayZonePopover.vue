<script setup lang="ts">
import { X } from 'lucide-vue-next';
import ReplayCardImage from './ReplayCardImage.vue';
import { useReplayContext } from './replayContext';
import { REPLAY_ZONES } from './replayZones';
import type { ReplayCard, ReplayZone, ZonePopoverPosition } from './types';

defineProps<{
    title: string;
    zone: ReplayZone;
    cards: ReplayCard[];
    position: ZonePopoverPosition;
}>();

const emit = defineEmits<{
    close: [];
}>();

const { showPreview, hidePreview } = useReplayContext();
</script>

<template>
    <div class="contents">
        <div class="absolute inset-0 z-60" @click="emit('close')" />
        <div
            class="absolute z-61 flex flex-col overflow-hidden rounded-[10px] border border-border bg-card shadow-[0_10px_30px_rgba(0,0,0,.35)]"
            :style="{
                left: `${position.left}px`,
                top: position.top != null ? `${position.top}px` : 'auto',
                bottom: position.bottom != null ? `${position.bottom}px` : 'auto',
                width: `${position.width}px`,
                maxHeight: `${position.maxHeight}px`,
            }"
        >
            <div class="flex flex-none items-center gap-2 border-b border-border py-2 pr-2 pl-3">
                <span class="font-semibold">{{ title }}</span>
                <span class="text-muted-foreground">{{ cards.length }}</span>
                <div class="flex-1" />
                <button
                    type="button"
                    class="grid size-6.5 cursor-pointer place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="emit('close')"
                >
                    <X :size="15" />
                </button>
            </div>
            <div v-if="!cards.length" class="px-3 py-4.5 text-center text-muted-foreground">
                {{ REPLAY_ZONES[zone].empty }}
            </div>
            <div v-else class="grid min-h-0 flex-1 grid-cols-[repeat(auto-fill,minmax(64px,1fr))] gap-1.5 overflow-y-auto p-2.5">
                <div
                    v-for="card in cards"
                    :key="card.Id"
                    class="relative aspect-[63/88] rounded"
                    @mouseenter="showPreview($event, card)"
                    @mouseleave="hidePreview"
                >
                    <ReplayCardImage :name="card.name" :image="card.image" />
                </div>
            </div>
        </div>
    </div>
</template>
