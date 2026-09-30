import { router } from '@inertiajs/vue3';
import { ref, type Ref } from 'vue';
import InstallController from '@/actions/App/Http/Controllers/Updates/InstallController';

// Module scope: one overlay regardless of which surface started the install.
const installing = ref(false);

export function useInstallUpdate(): { installing: Ref<boolean>; install: () => void } {
    function install() {
        if (installing.value) {
            return;
        }

        installing.value = true;

        // Give the overlay a moment on screen before the app quits.
        setTimeout(() => router.get(InstallController.url()), 3000);
    }

    return { installing, install };
}
