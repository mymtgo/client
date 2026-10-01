<script setup lang="ts">
import OverlayGamePips from '@/components/leagues/OverlayGamePips.vue';
import type { ChipTone } from '@/components/leagues/OverlayStatusChip.vue';
import OverlayStatusChip from '@/components/leagues/OverlayStatusChip.vue';
import { useOverlayDeckLabel } from '@/composables/useOverlayDeckLabel';
import { ArrowLeftRight, Trophy } from 'lucide-vue-next';
import { computed, useTemplateRef } from 'vue';

export type OverlayStatus = 'idle' | 'waiting' | 'in_game' | 'sideboarding' | 'complete' | 'trophied' | 'dropped';

export interface OverlayState {
    status: OverlayStatus;
    event: { kind: 'league'; name: string; format: string } | null;
    record: { wins: number; losses: number } | null;
    progress: { played: number; of: number } | null;
    match: { number: number; games: Array<{ won: boolean | null }>; gamesWon: number; gamesLost: number } | null;
    gameRecord: { won: number; lost: number } | null;
    deck: { id: number; name: string; archetype: string | null; colorIdentity: string[]; label: string | null } | null;
    art: { url: string } | null;
    size: 'full' | 'compact';
}

const props = defineProps<{ state: OverlayState }>();

const MANA: Record<string, string> = { W: '#f8e7b9', U: '#60a5fa', B: '#a78bfa', R: '#f87171', G: '#34d399' };

/** Border gradient per status; deck colours drive the live states. */
const border = computed(() => {
    switch (props.state.status) {
        case 'sideboarding':
            return 'linear-gradient(140deg,#fbbf24,rgb(180 83 9/.45) 50%,#fbbf24)';
        case 'complete':
            return 'linear-gradient(140deg,#6ee7b7,rgb(16 185 129/.6) 50%,#6ee7b7)';
        case 'trophied':
            return 'linear-gradient(135deg,var(--color-pink-400),var(--color-sky-300) 50%,var(--color-blue-400))';
        case 'dropped':
            return 'linear-gradient(140deg,#f87171,rgb(153 27 27/.45) 50%,#f87171)';
        case 'idle':
            return 'linear-gradient(140deg,rgb(148 163 184/.55),rgb(71 85 105/.35))';
        default: {
            const colors = (props.state.deck?.colorIdentity ?? []).map((c) => MANA[c]).filter(Boolean);
            const stops = colors.length ? colors : ['#60a5fa'];

            return `linear-gradient(140deg,${stops.length === 1 ? `${stops[0]},rgb(59 130 246/.35) 50%,${stops[0]}` : stops.join(',')})`;
        }
    }
});

const chip = computed<{ tone: ChipTone; label: string } | null>(() => {
    const { status, match, progress } = props.state;

    switch (status) {
        case 'in_game':
            return { tone: 'neutral', label: `Match ${match?.number ?? 1} of ${progress?.of ?? 5}` };
        case 'sideboarding':
            return { tone: 'amber', label: 'Sideboarding' };
        case 'waiting':
            return { tone: 'neutral', label: `Next: match ${(progress?.played ?? 0) + 1}` };
        case 'complete':
            return { tone: 'green', label: 'Complete' };
        case 'trophied':
            return { tone: 'trophy', label: 'Trophied!' };
        case 'dropped':
            return { tone: 'red', label: 'Dropped' };
        default:
            return null;
    }
});

const matchScore = computed(() => {
    const m = props.state.match;
    if (!m) return '';
    if (m.gamesWon > m.gamesLost) return `Up ${m.gamesWon}-${m.gamesLost}`;
    if (m.gamesWon < m.gamesLost) return `Down ${m.gamesWon}-${m.gamesLost}`;
    return `${m.gamesWon}-${m.gamesLost}`;
});

const gameNumber = computed(() => Math.max(1, props.state.match?.games.length ?? 1));

const subline = computed(() => {
    const { status, gameRecord, progress } = props.state;

    switch (status) {
        case 'sideboarding':
            return `${matchScore.value} · Game ${(props.state.match?.games.length ?? 0) + 1} next`;
        case 'waiting':
            return 'Looking for an opponent…';
        case 'complete':
        case 'trophied':
            return gameRecord ? `${gameRecord.won}-${gameRecord.lost} in games` : '';
        case 'dropped':
            return `Dropped after match ${progress?.played ?? 0}`;
        case 'idle':
            return 'Join one on MTGO to start tracking';
        default:
            return '';
    }
});

/** Untyped title: the archetype viewers recognise, else the MTGO deck name. */
const deckName = computed(() =>
    props.state.status === 'idle' ? 'Not in a league' : (props.state.deck?.archetype ?? props.state.deck?.name ?? 'Unknown deck'),
);
const record = computed(() => (props.state.record ? `${props.state.record.wins}-${props.state.record.losses}` : null));

/* Editable deck label, stored in app settings per deck id. */
const labelInput = useTemplateRef<HTMLInputElement>('labelInput');
const labelDeckId = computed(() => (props.state.deck?.id && props.state.status !== 'idle' ? props.state.deck.id : null));
const { label: customLabel, setLabel } = useOverlayDeckLabel(
    labelDeckId,
    computed(() => props.state.deck?.label ?? null),
);

function onLabelInput(event: Event) {
    setLabel((event.target as HTMLInputElement).value);
}

function blurLabel() {
    labelInput.value?.blur();
}
</script>

<template>
    <div
        class="h-full w-full p-[1.5px] font-sans text-white"
        :class="state.size === 'compact' ? 'rounded-[10px]' : 'rounded-[14px]'"
        :style="{ background: border, boxShadow: '0 3px 10px rgb(0 0 0 / .45)' }"
    >
        <div
            class="relative h-full overflow-hidden bg-[#0c1014] [text-shadow:0_0_2px_#000,0_1px_3px_rgb(0_0_0/.95),0_0_10px_rgb(0_0_0/.85),0_0_18px_rgb(0_0_0/.6)]"
            :class="state.size === 'compact' ? 'rounded-[8.5px]' : 'rounded-[12.5px]'"
        >
            <div v-if="state.art" class="absolute inset-0 bg-cover bg-[center_35%]" :style="{ backgroundImage: `url(${state.art.url})` }" />
            <div v-else class="absolute inset-0 bg-[radial-gradient(120%_140%_at_85%_0%,#1b2430,#0c1014_60%)]" />

            <!-- Full -->
            <div v-if="state.size === 'full'" class="relative flex h-full flex-col justify-between px-3.5 py-2.5">
                <div class="flex items-center justify-between gap-2">
                    <OverlayStatusChip v-if="state.event" tone="neutral" class="shrink"
                        ><span class="truncate">{{ state.event.name }}</span></OverlayStatusChip
                    >
                    <span v-else />
                    <OverlayStatusChip v-if="chip" :tone="chip.tone" class="shrink-0">
                        <ArrowLeftRight v-if="state.status === 'sideboarding'" class="size-3" />
                        <Trophy v-if="state.status === 'trophied'" class="size-3 text-sky-300" />
                        {{ chip.label }}
                    </OverlayStatusChip>
                </div>
                <div class="flex items-end justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <input
                            v-if="labelDeckId"
                            ref="labelInput"
                            type="text"
                            :value="customLabel"
                            :placeholder="deckName"
                            spellcheck="false"
                            class="block h-5 w-full min-w-0 truncate border-0 bg-transparent p-0 text-base leading-5 font-bold outline-none [text-shadow:inherit] placeholder:text-white placeholder:opacity-100 focus:rounded focus:bg-white/10 focus:px-1"
                            style="-webkit-app-region: no-drag"
                            @input="onLabelInput"
                            @keydown.enter.prevent="blurLabel"
                            @keydown.escape.prevent="blurLabel"
                        />
                        <div v-else class="h-5 truncate text-base leading-5 font-bold">{{ deckName }}</div>
                        <div
                            class="mt-0.5 flex items-center gap-1.5 text-[11px]"
                            :class="state.status === 'sideboarding' ? 'font-semibold text-amber-300' : 'text-white/70'"
                        >
                            <template v-if="state.status === 'in_game'">
                                Game {{ gameNumber }}
                                <OverlayGamePips :games="state.match?.games ?? []" :live="true" />
                            </template>
                            <template v-else>{{ subline }}</template>
                        </div>
                    </div>
                    <span v-if="record" class="shrink-0 text-[34px] leading-[.9] font-extrabold tabular-nums">{{ record }}</span>
                </div>
                <span
                    v-if="state.status === 'idle'"
                    class="pointer-events-none absolute right-2.5 bottom-1.5 text-[8px] tracking-[.16em] uppercase opacity-45"
                >
                    mymtgo.com
                </span>
            </div>

            <!-- Compact -->
            <div v-else class="relative flex h-full items-center gap-2 px-3">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-[13px] leading-tight font-bold">{{ customLabel || deckName }}</div>
                    <div
                        class="mt-0.5 flex items-center gap-1.5 text-[11px]"
                        :class="state.status === 'sideboarding' ? 'font-semibold text-amber-300' : 'text-white/70'"
                    >
                        <template v-if="state.status === 'in_game'">
                            {{ state.event?.name }} · G{{ gameNumber }}
                            <OverlayGamePips :games="state.match?.games ?? []" :live="true" />
                        </template>
                        <template v-else-if="state.status === 'sideboarding'">
                            <ArrowLeftRight class="size-3" /> Sideboarding · G{{ (state.match?.games.length ?? 0) + 1 }} next
                        </template>
                        <template v-else-if="chip">{{ chip.label }}</template>
                        <template v-else>{{ subline }}</template>
                    </div>
                </div>
                <span v-if="record" class="shrink-0 text-2xl leading-none font-extrabold tabular-nums">{{ record }}</span>
            </div>
        </div>
    </div>
</template>
