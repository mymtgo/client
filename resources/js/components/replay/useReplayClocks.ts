import { useRafFn } from '@vueuse/core';
import { computed, shallowRef, watch, type Ref } from 'vue';
import type { ReplayFrame } from './types';
import { frameDuration } from './useReplayPlayback';

/**
 * Chess clocks as shown on screen. Frames only sample TimeLeft, so during
 * playback each clock runs down towards the next frame's value over the time
 * the current frame is held, instead of sitting still and then snapping.
 * Paused or stepping, the recorded value is shown as is.
 */
export function useReplayClocks(
    frames: Ref<ReplayFrame[]>,
    current: Readonly<Ref<number>>,
    playing: Readonly<Ref<boolean>>,
    speed: Readonly<Ref<number>>,
) {
    const progress = shallowRef(0);
    let frameStartedAt = performance.now();

    const { pause, resume } = useRafFn(
        () => {
            progress.value = Math.min(1, (performance.now() - frameStartedAt) / frameDuration(frames.value, current.value, speed.value));
        },
        { immediate: false },
    );

    watch(current, () => {
        frameStartedAt = performance.now();
        progress.value = 0;
    });

    watch(
        playing,
        (isPlaying) => {
            if (isPlaying) {
                frameStartedAt = performance.now();
                resume();

                return;
            }

            pause();
            progress.value = 0;
        },
        { immediate: true },
    );

    return computed(() => {
        const clocks = new Map<number, number>();
        const next = new Map((frames.value[current.value + 1]?.content.Players ?? []).map((player) => [player.Id, player.TimeLeft]));

        (frames.value[current.value]?.content.Players ?? []).forEach((player) => {
            if (player.TimeLeft == null) {
                return;
            }

            const target = next.get(player.Id);
            const running = playing.value && target != null && target < player.TimeLeft;

            clocks.set(player.Id, running ? player.TimeLeft + (target - player.TimeLeft) * progress.value : player.TimeLeft);
        });

        return clocks;
    });
}
