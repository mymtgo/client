<script setup lang="ts">
import { computed, provide, shallowRef, toRef, useTemplateRef, watch } from 'vue';
import ReplayCardPreview from './ReplayCardPreview.vue';
import ReplayEmptyState from './ReplayEmptyState.vue';
import ReplayGameEnd from './ReplayGameEnd.vue';
import ReplayHand from './ReplayHand.vue';
import ReplayLogDrawer from './ReplayLogDrawer.vue';
import ReplayPhaseRail from './ReplayPhaseRail.vue';
import ReplayScrubTooltip from './ReplayScrubTooltip.vue';
import ReplaySide from './ReplaySide.vue';
import ReplayTransport from './ReplayTransport.vue';
import ReplayZonePopover from './ReplayZonePopover.vue';
import { replayContextKey } from './replayContext';
import { buildLogItems, deriveTurns } from './replayTimeline';
import { REPLAY_ZONES } from './replayZones';
import type { ReplayFrame, ReplayLogEntry, ReplayMatchGame } from './types';
import { useCardPreview } from './useCardPreview';
import { useReplayBoard } from './useReplayBoard';
import { useReplayClocks } from './useReplayClocks';
import { useReplayKeyboard } from './useReplayKeyboard';
import { useReplayPlayback } from './useReplayPlayback';
import { useScrubTooltip } from './useScrubTooltip';
import { useZonePopover } from './useZonePopover';

const props = defineProps<{
    frames: ReplayFrame[];
    log: ReplayLogEntry[];
    /** Whether you won this game; null when the result is unknown. */
    won: boolean | null;
    gameId: number;
    /** Every game of the match in play order. */
    matchGames: ReplayMatchGame[];
}>();

const root = useTemplateRef<HTMLElement>('root');
const frames = toRef(props, 'frames');

const total = computed(() => props.frames.length);
const turns = computed(() => deriveTurns(props.frames, props.log));
const logItems = computed(() => buildLogItems(props.frames, props.log, turns.value));

const playback = useReplayPlayback(frames, turns);
const { current, playing, speed } = playback;

const { frame, local, cards, cardsById, turn, activeId, step, pairs, stack, hand, sides, playerName } = useReplayBoard(frames, current, turns);

const clocks = useReplayClocks(frames, current, playing, speed);

const preview = useCardPreview(root, cardsById, playerName);
const { card: previewCard, position: previewPosition, chips: previewChips } = preview;
provide(replayContextKey, { showPreview: preview.showPreview, hidePreview: preview.hidePreview });

const popover = useZonePopover(root, cards);
const { open: openZone, zoneCards } = popover;

const { tooltip, setHover } = useScrubTooltip(root, frames, turns, logItems);

const logOpen = shallowRef(false);

function toggleLog() {
    logOpen.value = !logOpen.value;
}

function seekAndPause(index: number) {
    playback.pause();
    playback.seek(index);
}

function dismissOverlays() {
    popover.close();
    preview.hidePreview();
    logOpen.value = false;
}

useReplayKeyboard({ toggle: playback.toggle, step: playback.step, jumpTurn: playback.jumpTurn, toggleLog, dismiss: dismissOverlays });

const activeName = computed(() => (activeId.value !== null ? playerName(activeId.value) : null));
const activeIsLocal = computed(() => activeId.value !== null && activeId.value === local.value?.Id);
const phasesRecorded = computed(() => props.frames.some((item) => item.content.Step != null || item.content.Phase != null));
const atEnd = computed(() => total.value > 0 && current.value === total.value - 1);

/** Seat of the game's winner, held back until the final frame so the replay does not spoil the result. */
const winnerId = computed(() => {
    if (props.won === null || !atEnd.value) {
        return null;
    }

    return sides.value.find((side) => side.opponent !== props.won)?.player.Id ?? null;
});
const gameIndex = computed(() => props.matchGames.findIndex((item) => item.id === props.gameId));
const matchGame = computed(() => props.matchGames[gameIndex.value] ?? null);
const nextGame = computed(() => (gameIndex.value >= 0 ? (props.matchGames[gameIndex.value + 1] ?? null) : null));

const endDismissed = shallowRef(false);
const showGameEnd = computed(() => atEnd.value && !playing.value && !endDismissed.value);

watch(atEnd, (value) => {
    if (!value) {
        endDismissed.value = false;
    }
});

const localId = computed(() => local.value?.Id ?? null);
const popoverTitle = computed(() => (openZone.value ? `${playerName(openZone.value.player)} · ${REPLAY_ZONES[openZone.value.zone].label}` : ''));
</script>

<template>
    <div ref="root" class="texture-bg relative flex size-full min-h-0 flex-col overflow-hidden text-[13px] leading-snug text-foreground select-none">
        <ReplayEmptyState v-if="!total" />

        <template v-else>
            <div class="relative flex min-h-0 flex-1">
                <div class="relative flex min-w-0 flex-1 flex-col">
                    <ReplaySide
                        v-for="side in sides"
                        :key="side.player.Id"
                        :class="side.opponent ? 'order-0' : 'order-2'"
                        :player="side.player"
                        :battlefield="side.battlefield"
                        :opponent="side.opponent"
                        :active="side.active"
                        :priority="side.priority"
                        :time-left="clocks.get(side.player.Id) ?? null"
                        :winner="side.player.Id === winnerId"
                        :zone-counts="side.zoneCounts"
                        :open-zone="openZone?.player === side.player.Id ? openZone.zone : null"
                        :pairs="pairs"
                        @toggle-zone="(zone, event) => popover.toggle(side.player.Id, zone, event, side.opponent)"
                    />

                    <ReplayPhaseRail
                        :turn-number="turn?.number ?? null"
                        :active-name="activeName"
                        :active-is-local="activeIsLocal"
                        :step="step"
                        :phases-recorded="phasesRecorded"
                        :stack="stack"
                        :player-name="playerName"
                    />

                    <ReplayHand :cards="hand" :count="local?.HandCount ?? hand.length" />
                </div>

                <ReplayLogDrawer
                    :open="logOpen"
                    :items="logItems"
                    :current="current"
                    :local-id="localId"
                    :player-name="playerName"
                    @close="logOpen = false"
                    @seek="seekAndPause"
                />
            </div>

            <ReplayTransport
                :turns="turns"
                :current="current"
                :total="total"
                :playing="playing"
                :speed="speed"
                :timestamp="frame?.timestamp ?? ''"
                :local-id="localId"
                :log-open="logOpen"
                :game-id="gameId"
                :match-games="matchGames"
                :player-name="playerName"
                @toggle="playback.toggle"
                @step="playback.step"
                @jump-turn="playback.jumpTurn"
                @seek="playback.seek"
                @scrub-start="playback.pause"
                @set-speed="playback.setSpeed"
                @toggle-log="toggleLog"
                @hover="setHover"
            />

            <ReplayGameEnd
                v-if="showGameEnd"
                :game="matchGame"
                :next="nextGame"
                :won="won"
                @restart="playback.toggle"
                @dismiss="endDismissed = true"
            />

            <ReplayScrubTooltip v-if="tooltip" :tooltip="tooltip" />

            <ReplayZonePopover
                v-if="openZone"
                :title="popoverTitle"
                :zone="openZone.zone"
                :cards="zoneCards"
                :position="openZone.position"
                @close="popover.close"
            />

            <ReplayCardPreview v-if="previewCard && previewPosition" :card="previewCard" :chips="previewChips" :position="previewPosition" />
        </template>
    </div>
</template>
