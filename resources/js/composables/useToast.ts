import { ref } from 'vue';

export interface Toast {
    id: number;
    type: string;
    title: string;
    message: string;
    route?: string | null;
    duration: number;
}

const toasts = ref<Toast[]>([]);
let nextId = 0;

const MAX_VISIBLE = 3;

export function useToast() {
    function add(options: Omit<Toast, 'id' | 'duration'> & { duration?: number }) {
        const toast: Toast = {
            id: nextId++,
            duration: options.duration ?? 5000,
            ...options,
        };

        toasts.value.push(toast);

        // Trim oldest if over max
        while (toasts.value.length > MAX_VISIBLE) {
            toasts.value.shift();
        }

        return toast.id;
    }

    function remove(id: number) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    return { toasts, add, remove };
}
