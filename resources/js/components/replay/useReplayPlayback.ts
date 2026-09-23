import { computed, onUnmounted, readonly, shallowRef, watch, type Ref } from 'vue';
import { timestampMs } from './replayTimeline';
import type { ReplayFrame, ReplayTurn } from './types';

export const REPLAY_SPEEDS = [0.5, 1, 1.5, 2, 4];

const DAY_MS = 24 * 60 * 60 * 1000;

/** Real gaps are replayed as they happened, within these bounds, so long thinks do not drag. */
const MIN_FRAME_MS = 250;
const MAX_FRAME_MS = 4000;

/** How long playback holds a frame: the real time until the next one, scaled by speed. */
export function frameDuration(frames: ReplayFrame[], index: number, speed: number): number {
    const from = frames[index];
    const to = frames[index + 1];

    if (!from || !to) {
        return MIN_FRAME_MS / speed;
    }

    let gap = timestampMs(to.timestamp) - timestampMs(from.timestamp);

    // Timestamps carry no date, so a game running past midnight wraps.
    if (gap < 0) {
        gap += DAY_MS;
    }

    return Math.min(MAX_FRAME_MS, Math.max(MIN_FRAME_MS, gap)) / speed;
}

/** Frame cursor, play/pause and turn jumps for a replay. */
export function useReplayPlayback(frames: Ref<ReplayFrame[]>, turns: Ref<ReplayTurn[]>) {
    const frameCount = computed(() => frames.value.length);
    const current = shallowRef(0);
    const playing = shallowRef(false);
    const speed = shallowRef(1);
    let timer: ReturnType<typeof setTimeout> | null = null;

    function clamp(index: number): number {
        return Math.max(0, Math.min(frameCount.value - 1, index));
    }

    function stopTimer() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function pause() {
        stopTimer();
        playing.value = false;
    }

    function seek(index: number) {
        current.value = clamp(index);
    }

    function step(delta: number) {
        pause();
        seek(current.value + delta);
    }

    function schedule() {
        stopTimer();
        timer = setTimeout(
            () => {
                if (current.value >= frameCount.value - 1) {
                    pause();

                    return;
                }

                current.value++;
                schedule();
            },
            frameDuration(frames.value, current.value, speed.value),
        );
    }

    function toggle() {
        if (playing.value) {
            pause();

            return;
        }

        if (current.value >= frameCount.value - 1) {
            current.value = 0;
        }

        playing.value = true;
        schedule();
    }

    function setSpeed(value: number) {
        speed.value = value;

        if (playing.value) {
            schedule();
        }
    }

    /**
     * Back goes to the start of the current turn, or the previous turn when
     * already at (or one frame past) its start. Forward goes to the next turn.
     */
    function jumpTurn(direction: -1 | 1) {
        pause();
        const bounds = turns.value;

        if (direction > 0) {
            const next = bounds.find((turn) => turn.from > current.value);
            seek(next ? next.from : frameCount.value - 1);

            return;
        }

        const index = bounds.findLastIndex((turn) => turn.from <= current.value);
        const turn = bounds[index];

        if (turn && current.value - turn.from > 1) {
            seek(turn.from);

            return;
        }

        seek(bounds[index - 1]?.from ?? 0);
    }

    watch(frameCount, () => seek(current.value));

    onUnmounted(stopTimer);

    return { current: readonly(current), playing: readonly(playing), speed: readonly(speed), seek, step, toggle, pause, setSpeed, jumpTurn };
}
