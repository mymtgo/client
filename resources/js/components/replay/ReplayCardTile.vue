<script setup lang="ts">
import { Shield, Swords } from 'lucide-vue-next';
import { computed } from 'vue';
import ReplayCardImage from './ReplayCardImage.vue';
import { counterList, isCreature } from './replayCards';
import { useReplayContext } from './replayContext';
import type { ReplayCard } from './types';

const props = defineProps<{
    card: ReplayCard;
    /** Collapsed land count; shows a ×N badge above 1. */
    count?: number;
    /** Opponent cards lift down toward the centre line when attacking, yours lift up. */
    opponent: boolean;
    /** Shared number linking a blocker to the attacker it blocks. */
    pair?: number | null;
    /** The last card in a row never shrinks, so crowded rows overlap instead of wrapping. */
    last: boolean;
}>();

const { showPreview, hidePreview } = useReplayContext();

const tapped = computed(() => !!props.card.Tapped);
const attacking = computed(() => props.card.Attacking != null);
const blocking = computed(() => props.card.Blocking != null);
const counters = computed(() => counterList(props.card));
const showPt = computed(() => isCreature(props.card) && props.card.Power != null);

const lift = computed(() => (attacking.value ? (props.opponent ? 14 : -14) : 0));

const ring = computed(() => (attacking.value ? '#e5484d' : blocking.value ? '#f5a524' : 'transparent'));
</script>

<template>
    <div
        class="relative h-full min-w-[18px] hover:z-20!"
        :style="{
            aspectRatio: tapped ? '1 / 1' : '63 / 88',
            flex: `0 ${last ? 0 : 1} auto`,
            zIndex: attacking ? 3 : 1,
        }"
    >
        <div
            class="absolute top-0 left-0 aspect-[63/88] h-full rounded-[5px] bg-muted transition-[transform,box-shadow] duration-200 ease-out"
            :style="{
                boxShadow: `0 0 0 2px ${ring}, 0 2px 6px rgba(0,0,0,.35)`,
                transform: `translate(${tapped ? 19.8 : 0}%, ${lift}px) rotate(${tapped ? 90 : 0}deg)`,
            }"
            @mouseenter="showPreview($event, card)"
            @mouseleave="hidePreview"
        >
            <ReplayCardImage :name="card.name" :type="card.type" :image="card.image" show-type />

            <div
                v-if="(count ?? 1) > 1"
                class="absolute bottom-[7%] left-1/2 -translate-x-1/2 rounded-full bg-[rgba(12,12,12,.88)] px-1.75 py-px text-[11px] font-bold text-white"
            >
                ×{{ count }}
            </div>

            <div v-if="counters.length" class="absolute top-[16%] left-0.75 flex flex-col items-start gap-0.5">
                <span
                    v-for="counter in counters"
                    :key="counter.kind"
                    class="rounded bg-[#f3eedf] px-1.25 py-px text-[10px] font-bold whitespace-nowrap text-[#1a1a1a] shadow-[0_1px_2px_rgba(0,0,0,.4)]"
                >
                    {{ counter.label }}
                </span>
            </div>

            <div
                v-if="attacking"
                title="Attacking"
                class="absolute top-0.75 right-0.75 flex h-4.5 items-center gap-0.5 rounded-full bg-[#e5484d] px-1.25 text-[10px] font-bold text-white"
            >
                <Swords :size="11" :stroke-width="2.5" />
                <span v-if="pair">{{ pair }}</span>
            </div>
            <div
                v-else-if="blocking"
                title="Blocking"
                class="absolute top-0.75 right-0.75 flex h-4.5 items-center gap-0.5 rounded-full bg-[#f5a524] px-1.25 text-[10px] font-bold text-[#1a1a1a]"
            >
                <Shield :size="11" :stroke-width="2.5" />
                <span v-if="pair">{{ pair }}</span>
            </div>

            <div
                v-if="(card.Damage ?? 0) > 0"
                class="absolute bottom-0.75 left-0.75 rounded bg-[#e5484d] px-1.25 py-px text-[10px] font-bold text-white"
            >
                {{ card.Damage }} dmg
            </div>

            <div
                v-if="showPt"
                class="absolute right-0.75 bottom-0.75 rounded bg-[rgba(12,12,12,.88)] px-1.25 py-px font-mono text-[11px] font-bold text-white"
            >
                {{ card.Power }}/{{ card.Toughness }}
            </div>
        </div>
    </div>
</template>
