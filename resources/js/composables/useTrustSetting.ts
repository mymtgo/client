import { useDebounceFn } from '@vueuse/core';
import { ref, type Ref } from 'vue';
import UpdateTrustSettingController from '@/actions/App/Http/Controllers/Settings/UpdateTrustSettingController';
import { useToast } from '@/composables/useToast';

const MIN = 0;
const HARD_MAX = 100000;
const DEBOUNCE_MS = 300;

function clamp(value: number): number {
    if (!Number.isFinite(value)) return 50;
    const int = Math.round(value);
    if (int < MIN) return MIN;
    if (int > HARD_MAX) return HARD_MAX;
    return int;
}

export interface UseTrustSetting {
    readonly value: Ref<number>;
    setValue(next: number): void;
    reset(): void;
}

export function useTrustSetting(initial: number, defaultValue = 50): UseTrustSetting {
    const value = ref<number>(clamp(initial));
    let lastPersisted = value.value;
    let abortController: AbortController | null = null;
    const { add: toast } = useToast();

    async function persist(next: number): Promise<void> {
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        try {
            const definition = UpdateTrustSettingController.patch();
            const url = definition.url;
            const method = definition.method.toUpperCase();
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ value: next }),
                signal: abortController.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            lastPersisted = next;
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') {
                return;
            }
            value.value = lastPersisted;
            toast({
                type: 'error',
                title: 'Could not save trust preference',
                message: 'Your slider value will reset next time you open the page.',
            });
        } finally {
            abortController = null;
        }
    }

    const debouncedPersist = useDebounceFn((next: number) => {
        void persist(next);
    }, DEBOUNCE_MS);

    function setValue(next: number): void {
        const clamped = clamp(next);
        if (clamped === value.value) return;
        value.value = clamped;
        debouncedPersist(clamped);
    }

    function reset(): void {
        setValue(defaultValue);
    }

    return { value, setValue, reset };
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
