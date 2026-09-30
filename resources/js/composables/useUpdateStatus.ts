import { router, usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';
import type { UpdateStatus } from '@/types/update';

const UPDATER_EVENTS = ['CheckingForUpdate', 'UpdateAvailable', 'UpdateDownloaded', 'UpdateNotAvailable', 'Error'].map(
    (name) => `Native\\Desktop\\Events\\AutoUpdater\\${name}`,
);

let listening = false;

/**
 * The shared `update` prop, kept live: every updater event reloads just
 * that prop so the banner, status bar and settings card move together.
 */
export function useUpdateStatus(): ComputedRef<UpdateStatus> {
    const page = usePage<{ update: UpdateStatus }>();

    if (!listening && window.Native) {
        listening = true;

        for (const event of UPDATER_EVENTS) {
            window.Native.on(event, () => router.reload({ only: ['update'] }));
        }
    }

    return computed(() => page.props.update);
}
