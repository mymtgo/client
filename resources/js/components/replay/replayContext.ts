import { inject, type InjectionKey } from 'vue';
import type { ReplayCard } from './types';

/** Card preview hooks shared by every card surface in the viewer. */
export type ReplayContext = {
    showPreview: (event: MouseEvent, card: ReplayCard) => void;
    hidePreview: () => void;
};

export const replayContextKey: InjectionKey<ReplayContext> = Symbol('replay');

export function useReplayContext(): ReplayContext {
    const context = inject(replayContextKey);

    if (!context) {
        throw new Error('Replay components must be rendered inside ReplayViewer.');
    }

    return context;
}
