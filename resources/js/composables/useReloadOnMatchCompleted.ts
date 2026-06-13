import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

/**
 * Partial-reload the current page's match-derived props when the backend
 * broadcasts MatchCompleted.
 *
 * window.Native.on has no unsubscribe (it registers a permanent ipcRenderer
 * listener), so this keeps a module-level singleton: one Native.on
 * registration ever, with a registry of the currently-mounted page's prop
 * names. The listener is inert when no subscribed page is mounted.
 *
 * The registry reference-counts prop names rather than using a Set: two pages
 * can list the same prop, and Vue's mount/unmount ordering during an Inertia
 * page swap must not be able to drop a prop the incoming page still needs.
 */
const activeProps = new Map<string, number>();
let registered = false;

export function useReloadOnMatchCompleted(only: readonly string[]) {
    onMounted(() => {
        only.forEach((prop) => activeProps.set(prop, (activeProps.get(prop) ?? 0) + 1));

        if (!registered) {
            registered = true;
            window.Native?.on('App\\Events\\MatchCompleted', () => {
                if (activeProps.size > 0) {
                    router.reload({ only: [...activeProps.keys()] });
                }
            });
        }
    });

    onUnmounted(() => {
        only.forEach((prop) => {
            const count = activeProps.get(prop) ?? 0;

            if (count <= 1) {
                activeProps.delete(prop);
            } else {
                activeProps.set(prop, count - 1);
            }
        });
    });
}
