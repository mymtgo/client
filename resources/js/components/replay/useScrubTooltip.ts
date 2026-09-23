import { computed, shallowRef, type Ref } from 'vue';
import { normaliseStep, stepLabel } from './replayPhases';
import { turnAt } from './replayTimeline';
import type { ReplayFrame, ReplayLogItem, ReplayTurn, ScrubHover, ScrubTooltipView } from './types';

const TOOLTIP_WIDTH_PX = 240;

/** Hover card above the scrubber: turn, step, time and the latest log line. */
export function useScrubTooltip(
    root: Readonly<Ref<HTMLElement | null>>,
    frames: Ref<ReplayFrame[]>,
    turns: Ref<ReplayTurn[]>,
    logItems: Ref<ReplayLogItem[]>,
) {
    const hover = shallowRef<ScrubHover | null>(null);

    function setHover(value: ScrubHover | null) {
        hover.value = value;
    }

    const tooltip = computed<ScrubTooltipView | null>(() => {
        const state = hover.value;
        const hovered = state ? frames.value[state.frame] : undefined;

        if (!state || !hovered || !root.value) {
            return null;
        }

        const bounds = root.value.getBoundingClientRect();
        const turn = turnAt(turns.value, state.frame);
        const step = normaliseStep(hovered.content.Step, hovered.content.Phase);
        const line = logItems.value.findLast((item) => item.kind === 'line' && item.frame <= state.frame);

        let title = `Frame ${state.frame + 1} of ${frames.value.length}`;

        if (turn?.number != null) {
            title = step ? `Turn ${turn.number} · ${stepLabel(step)}` : `Turn ${turn.number}`;
        }

        return {
            left: Math.max(8, Math.min(state.clientX - bounds.left - TOOLTIP_WIDTH_PX / 2, bounds.width - TOOLTIP_WIDTH_PX - 8)),
            bottom: bounds.bottom - state.trackTop + 10,
            title,
            time: hovered.timestamp,
            sub: line?.text ?? 'Game start',
        };
    });

    return { tooltip, setHover };
}
