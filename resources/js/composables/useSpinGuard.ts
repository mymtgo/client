import { type Ref, ref } from 'vue';

/**
 * Returns a reactive flag and a start function that guarantees the flag
 * stays true for at least `ms` milliseconds (one full icon spin).
 */
export function useSpinGuard(ms = 700): [Ref<boolean>, () => () => void] {
    const spinning = ref(false);

    function start() {
        const t = Date.now();
        spinning.value = true;
        return () => {
            const remaining = ms - (Date.now() - t);
            if (remaining > 0) setTimeout(() => (spinning.value = false), remaining);
            else spinning.value = false;
        };
    }

    return [spinning, start];
}
