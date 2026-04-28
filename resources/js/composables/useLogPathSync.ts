import BrowseFolderController from '@/actions/App/Http/Controllers/Settings/BrowseFolderController';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

export type PathStatus = { valid: boolean; fileCount: number; message: string };

export type PathKey = 'logPath' | 'dataPath';

export interface UseLogPathSyncOptions {
    initial: string;
    saveUrl: string;
}

export interface UseLogPathSync {
    input: string;
    processing: boolean;
    save: () => void;
    browse: () => Promise<void>;
}

export function useLogPathSync(options: UseLogPathSyncOptions): UseLogPathSync {
    const input = ref(options.initial);
    const processing = ref(false);

    const save = () => {
        processing.value = true;
        router.patch(
            options.saveUrl,
            { path: input.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    };

    const browse = async () => {
        processing.value = true;
        try {
            const response = await fetch(BrowseFolderController.url({ query: { default: input.value } }));
            const { path } = (await response.json()) as { path?: string };

            if (path) {
                input.value = path;
                router.patch(
                    options.saveUrl,
                    { path },
                    {
                        preserveScroll: true,
                        onFinish: () => {
                            processing.value = false;
                        },
                    },
                );
            } else {
                processing.value = false;
            }
        } catch {
            processing.value = false;
        }
    };

    return reactive({ input, processing, save, browse });
}
