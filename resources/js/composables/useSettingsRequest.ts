import { router } from '@inertiajs/vue3';
import { type Ref, ref } from 'vue';

type Method = 'patch' | 'post';

/**
 * Tracks which settings control is mid-request so a card can disable exactly
 * that control. Keys are free-form; one instance per card keeps them local.
 */
export function useSettingsRequest(): {
    processing: Ref<string | null>;
    send: (key: string, method: Method, url: string, data?: Record<string, unknown>) => void;
} {
    const processing = ref<string | null>(null);

    function send(key: string, method: Method, url: string, data: Record<string, unknown> = {}) {
        processing.value = key;
        router[method](url, data, {
            preserveScroll: true,
            onFinish: () => {
                processing.value = null;
            },
        });
    }

    return { processing, send };
}
