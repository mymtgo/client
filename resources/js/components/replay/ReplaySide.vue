<script setup lang="ts">
import { computed } from 'vue';
import ReplayCardTile from './ReplayCardTile.vue';
import ReplayPlayerHud from './ReplayPlayerHud.vue';
import { backRowGroups, isFrontRow, type CardGroup } from './replayCards';
import type { ReplayCard, ReplayPlayer, ReplayZone } from './types';

const props = defineProps<{
    player: ReplayPlayer;
    battlefield: ReplayCard[];
    opponent: boolean;
    active: boolean;
    priority: boolean;
    timeLeft: number | null;
    winner: boolean;
    zoneCounts: Record<ReplayZone, number>;
    openZone: ReplayZone | null;
    pairs: Map<number, number>;
}>();

const emit = defineEmits<{
    toggleZone: [zone: ReplayZone, event: MouseEvent];
}>();

const front = computed<CardGroup[]>(() => props.battlefield.filter(isFrontRow).map((card) => ({ card, count: 1 })));
const back = computed(() => backRowGroups(props.battlefield.filter((card) => !isFrontRow(card))));

/** Creatures face the centre line; lands and other permanents sit behind them. */
const rows = computed(() => {
    const creatures = { key: 'front', groups: front.value, grow: 1.3 };
    const lands = { key: 'back', groups: back.value, grow: 1 };

    return props.opponent ? [lands, creatures] : [creatures, lands];
});
</script>

<template>
    <div class="flex min-h-0 flex-1 basis-0 flex-col px-2" :class="opponent ? 'pt-2' : 'pb-2'">
        <ReplayPlayerHud
            :class="opponent ? 'order-0' : 'order-2'"
            :player="player"
            :opponent="opponent"
            :active="active"
            :priority="priority"
            :time-left="timeLeft"
            :winner="winner"
            :zone-counts="zoneCounts"
            :open-zone="openZone"
            @toggle-zone="(zone, event) => emit('toggleZone', zone, event)"
        />

        <div class="order-1 flex min-h-0 flex-1 basis-0 flex-col py-1">
            <div
                v-for="row in rows"
                :key="row.key"
                class="flex min-h-0 basis-0 items-stretch gap-1.5 px-2 py-1.75"
                :style="{ flexGrow: row.grow, flexShrink: 1 }"
            >
                <ReplayCardTile
                    v-for="(group, i) in row.groups"
                    :key="`${group.card.Id}-${group.count}`"
                    :card="group.card"
                    :count="group.count"
                    :opponent="opponent"
                    :pair="pairs.get(group.card.Id) ?? null"
                    :last="i === row.groups.length - 1"
                />
            </div>
        </div>
    </div>
</template>
