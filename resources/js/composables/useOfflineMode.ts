import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/**
 * Read the global `offlineMode` Inertia prop.
 *
 * Single cast for the whole app: there is no typed shared-props contract, and
 * this prop is read from many components.
 */
export function useOfflineMode(): ComputedRef<boolean> {
    const page = usePage();

    return computed(() => (page.props as Record<string, unknown>).offlineMode === true);
}
