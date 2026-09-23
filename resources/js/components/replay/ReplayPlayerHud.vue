<script setup lang="ts">
import ManaSymbol from '@/components/ManaSymbol.vue';
import { Card } from '@/components/ui/card';
import { Hand, Heart, Layers, Timer, Trophy } from 'lucide-vue-next';
import { computed } from 'vue';
import ReplayToggleButton from './ReplayToggleButton.vue';
import { poolSymbols } from './replayCards';
import { hudZones } from './replayZones';
import type { ReplayPlayer, ReplayZone } from './types';

const LOW_CLOCK_MS = 5 * 60 * 1000;

const props = defineProps<{
    player: ReplayPlayer;
    opponent: boolean;
    active: boolean;
    priority: boolean;
    /** Displayed clock in ms, which runs between frames during playback. */
    timeLeft: number | null;
    /** Won this game. */
    winner: boolean;
    zoneCounts: Record<ReplayZone, number>;
    openZone: ReplayZone | null;
}>();

const emit = defineEmits<{
    toggleZone: [zone: ReplayZone, event: MouseEvent];
}>();

const pool = computed(() => poolSymbols(props.player.Pool));

const clock = computed(() => {
    if (props.timeLeft == null) {
        return null;
    }

    const seconds = Math.max(0, Math.round(props.timeLeft / 1000));

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

const clockClass = computed(() => {
    if ((props.timeLeft ?? Infinity) < LOW_CLOCK_MS) {
        return 'text-[#e5484d]';
    }

    return props.priority ? 'text-foreground' : 'text-muted-foreground';
});

const zones = computed(() => hudZones(props.opponent));
</script>

<template>
    <Card class="h-11.5 flex-none flex-row items-center gap-3.5 px-3.5 py-0">
        <div
            class="grid size-6.5 flex-none place-items-center rounded-full text-xs font-bold transition-shadow"
            :class="[
                opponent ? 'bg-accent text-foreground' : 'bg-primary text-primary-foreground',
                active ? 'ring-2 ring-primary ring-offset-2 ring-offset-transparent' : '',
            ]"
        >
            {{ player.Name.charAt(0).toUpperCase() }}
        </div>

        <div class="flex min-w-0 flex-[0_1_auto] items-baseline gap-1.5 overflow-hidden">
            <span class="truncate text-sm font-semibold">{{ player.Name }}</span>
            <span v-if="player.IsLocal" class="text-[11px] text-muted-foreground">you</span>
        </div>

        <!-- Always laid out so the stats to its right never shift as priority passes. -->
        <span
            title="Holds priority"
            :aria-hidden="!priority"
            class="inline-flex h-5 flex-none items-center gap-1.25 rounded-full bg-primary/15 px-1.75 text-[11px] font-semibold transition-opacity duration-150"
            :class="priority ? 'opacity-100' : 'opacity-0'"
        >
            <span class="size-1.5 animate-pulse rounded-full bg-primary" />
            Priority
        </span>

        <div v-if="player.Life != null" title="Life" class="flex flex-none items-center gap-1.25">
            <Heart :size="15" class="text-[#e5484d]" />
            <span class="min-w-[2ch] text-[17px] font-bold tabular-nums" :class="player.Life <= 5 ? 'text-[#e5484d]' : ''">{{ player.Life }}</span>
        </div>

        <div v-if="clock" title="Chess clock" class="flex flex-none items-center gap-1" :class="clockClass">
            <Timer :size="14" />
            <span class="min-w-[5ch] font-mono text-[12.5px] tabular-nums">{{ clock }}</span>
        </div>

        <div v-if="player.HandCount != null" title="Cards in hand" class="flex flex-none items-center gap-1 text-muted-foreground">
            <Hand :size="14" />
            <span class="min-w-[2ch] text-foreground tabular-nums">{{ player.HandCount }}</span>
        </div>

        <div v-if="player.LibraryCount != null" title="Cards in library" class="flex flex-none items-center gap-1 text-muted-foreground">
            <Layers :size="14" />
            <span class="min-w-[2ch] text-foreground tabular-nums">{{ player.LibraryCount }}</span>
        </div>

        <div v-if="winner" title="Won this game" class="flex flex-none items-center gap-1 text-[12.5px] font-semibold text-yellow-400">
            <Trophy :size="14" />
            <span>Winner</span>
        </div>

        <div v-if="pool.length" title="Mana pool" class="flex flex-none items-center gap-0.75">
            <ManaSymbol v-for="(symbol, i) in pool" :key="`${symbol}-${i}`" :symbol="symbol" class="size-4.25" />
        </div>

        <div class="min-w-0 flex-1" />

        <ReplayToggleButton
            v-for="item in zones"
            :key="item.zone"
            :active="openZone === item.zone"
            :icon="item.icon"
            :title="item.zone === 'Hand' ? 'Revealed cards in hand' : undefined"
            @click="emit('toggleZone', item.zone, $event)"
        >
            <span>{{ item.label }}</span>
            <span class="text-muted-foreground tabular-nums">{{ zoneCounts[item.zone] }}</span>
        </ReplayToggleButton>
    </Card>
</template>
