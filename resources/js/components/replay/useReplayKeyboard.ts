import { onMounted, onUnmounted } from 'vue';

export type ReplayKeyboardActions = {
    toggle: () => void;
    step: (delta: number) => void;
    jumpTurn: (direction: -1 | 1) => void;
    toggleLog: () => void;
    dismiss: () => void;
};

/** Space play/pause, arrows step, Shift+arrows jump turns, L log, Escape closes overlays. */
export function useReplayKeyboard(actions: ReplayKeyboardActions) {
    function onKeydown(event: KeyboardEvent) {
        if (event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }

        switch (event.key) {
            case ' ':
                event.preventDefault();
                actions.toggle();
                break;
            case 'ArrowRight':
            case 'ArrowLeft': {
                event.preventDefault();
                const direction = event.key === 'ArrowRight' ? 1 : -1;

                if (event.shiftKey) {
                    actions.jumpTurn(direction);
                } else {
                    actions.step(direction);
                }

                break;
            }
            case 'l':
            case 'L':
                actions.toggleLog();
                break;
            case 'Escape':
                actions.dismiss();
                break;
        }
    }

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onUnmounted(() => window.removeEventListener('keydown', onKeydown));
}
