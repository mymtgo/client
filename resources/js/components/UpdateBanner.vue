<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import InstallController from '@/actions/App/Http/Controllers/Updates/InstallController';
import { Button } from '@/components/ui/button';

const page = usePage<{
    availableUpdate: { version: string; releaseName: string; releaseNotes: string } | null;
}>();

const ipcUpdate = ref<{ version: string; releaseName: string; releaseNotes: string } | null>(null);
const update = computed(() => page.props.availableUpdate ?? ipcUpdate.value);
const installing = ref(false);

onMounted(() => {
    window.Native?.on('Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded', (payload: Record<string, unknown>) => {
        ipcUpdate.value = {
            version: payload.version as string,
            releaseName: (payload.releaseName as string) ?? '',
            releaseNotes: (payload.releaseNotes as string) ?? '',
        };
    });
});

function install() {
    installing.value = true;

    setTimeout(() => {
        router.get(InstallController.url());
    }, 3000);
}
</script>

<template>
    <!-- Installing overlay -->
    <Teleport to="body">
        <div
            v-if="installing"
            class="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-4 bg-background"
        >
            <Download :size="48" class="animate-bounce text-primary" />
            <h2 class="text-lg font-semibold">Installing update...</h2>
            <p class="max-w-sm text-center text-sm text-muted-foreground">
                The app will close and restart automatically.
                This may take a few minutes — please don't reopen the app manually.
            </p>
        </div>
    </Teleport>

    <!-- Update banner -->
    <div
        v-if="update && !installing"
        class="flex shrink-0 items-center justify-between gap-3 border-b border-primary/20 bg-primary/10 px-4 py-2"
    >
        <div class="flex items-center gap-2 text-sm">
            <Download :size="14" class="text-primary" />
            <span>Update <strong>{{ update.version }}</strong> is ready to install</span>
        </div>
        <Button size="sm" variant="default" @click="install">
            Install & Restart
        </Button>
    </div>
</template>
