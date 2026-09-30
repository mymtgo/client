import { router } from '@inertiajs/vue3';
import { type Ref, ref } from 'vue';

type Method = 'patch' | 'post' | 'delete';
type Payload = Parameters<typeof router.post>[1];

/**
 * Tracks which settings control is mid-request so a card can disable exactly
 * that control. Keys are free-form; one instance per card keeps them local.
 */
export function useSettingsRequest(): {
    processing: Ref<string | null>;
    send: (key: string, method: Method, url: string, data?: Payload) => void;
} {
    const processing = ref<string | null>(null);

    function send(key: string, method: Method, url: string, data: Payload = {}) {
        processing.value = key;

        const options = {
            preserveScroll: true,
            onFinish: () => {
                processing.value = null;
            },
        };

        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router[method](url, data, options);
        }
    }

    return { processing, send };
}
