import { computed, readonly, shallowRef, type Ref } from 'vue';
import type { ReplayCard, ReplayZone, ZonePopoverPosition } from './types';

const MAX_WIDTH_PX = 320;
const MIN_HEIGHT_PX = 160;

/** Graveyard and exile browser anchored to the HUD button that opened it. */
export function useZonePopover(root: Readonly<Ref<HTMLElement | null>>, cards: Ref<ReplayCard[]>) {
    const open = shallowRef<{ player: number; zone: ReplayZone; position: ZonePopoverPosition } | null>(null);

    /** Opponent HUD sits at the top, so its popover opens downward; yours opens upward. */
    function toggle(player: number, zone: ReplayZone, event: MouseEvent, opensDown: boolean) {
        if (open.value?.player === player && open.value.zone === zone) {
            close();

            return;
        }

        if (!root.value) {
            return;
        }

        const bounds = root.value.getBoundingClientRect();
        const target = (event.currentTarget as HTMLElement).getBoundingClientRect();
        const width = Math.min(MAX_WIDTH_PX, bounds.width - 16);
        const left = Math.max(8, Math.min(target.right - bounds.left - width, bounds.width - width - 8));

        const position: ZonePopoverPosition = opensDown
            ? { left, width, top: target.bottom - bounds.top + 6, maxHeight: Math.max(MIN_HEIGHT_PX, bounds.bottom - target.bottom - 20) }
            : { left, width, bottom: bounds.bottom - target.top + 6, maxHeight: Math.max(MIN_HEIGHT_PX, target.top - bounds.top - 20) };

        open.value = { player, zone, position };
    }

    function close() {
        open.value = null;
    }

    /** Most recent first. */
    const zoneCards = computed(() => {
        const state = open.value;

        return state ? cards.value.filter((card) => card.Zone === state.zone && card.Owner === state.player).reverse() : [];
    });

    return { open: readonly(open), zoneCards, toggle, close };
}
