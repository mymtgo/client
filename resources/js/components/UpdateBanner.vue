<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import InstallController from '@/actions/App/Http/Controllers/Updates/InstallController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const page = usePage<{
    availableUpdate: { version: string; releaseName: string; releaseNotes: string } | null;
}>();

const ipcUpdate = ref<{ version: string; releaseName: string; releaseNotes: string } | null>(null);
const update = computed(() => page.props.availableUpdate ?? ipcUpdate.value);
const installing = ref(false);
const showModal = ref(false);
const dismissedVersion = ref<string | null>(sessionStorage.getItem('update_dismissed'));

onMounted(() => {
    window.Native?.on('Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded', (payload: Record<string, unknown>) => {
        ipcUpdate.value = {
            version: payload.version as string,
            releaseName: (payload.releaseName as string) ?? '',
            releaseNotes: (payload.releaseNotes as string) ?? '',
        };
    });
});

// Auto-open modal when an update becomes available (once per version)
watch(update, (val) => {
    if (val && !installing.value && dismissedVersion.value !== val.version) {
        showModal.value = true;
    }
}, { immediate: true });

function dismiss() {
    showModal.value = false;
    if (update.value) {
        dismissedVersion.value = update.value.version;
        sessionStorage.setItem('update_dismissed', update.value.version);
    }
}

function install() {
    installing.value = true;
    showModal.value = false;

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

    <!-- Update modal -->
    <Dialog v-model:open="showModal">
        <DialogContent v-if="update" class="max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Download :size="18" class="text-primary" />
                    Update {{ update.version }} Ready
                </DialogTitle>
                <DialogDescription>
                    A new version is ready to install. Review the changes below.
                </DialogDescription>
            </DialogHeader>

            <div class="max-h-64 overflow-y-auto rounded-md border bg-muted/30 p-4 text-sm leading-relaxed">
                <div
                    v-if="update.releaseNotes"
                    class="prose prose-sm prose-invert max-w-none [&_strong]:text-foreground [&_ul]:list-disc [&_ul]:pl-4 [&_li]:my-0.5"
                    v-html="update.releaseNotes"
                />
                <p v-else class="text-muted-foreground">No release notes available.</p>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="dismiss">Later</Button>
                <Button @click="install">
                    <Download class="mr-1.5 size-4" />
                    Install & Restart
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
