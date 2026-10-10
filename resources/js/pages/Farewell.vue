<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';

// The farewell release (0.47.0): every page of this app is this screen, full
// window, with no sidebar or status bar.
defineOptions({
    layout: (h: unknown, page: unknown) => page,
});

defineProps<{
    title: string;
    body: string;
    downloadLabel: string;
    downloadUrl: string;
    smartScreen: string;
    uninstall: string;
}>();

function openDownload() {
    // The server opens the download page in the system browser.
    router.get('/farewell/download', {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen items-center justify-center p-8">
        <div class="flex max-w-xl flex-col items-center gap-6 text-center">
            <h1 class="text-3xl font-bold text-foreground">{{ title }}</h1>
            <p class="text-base text-muted-foreground">{{ body }}</p>
            <Button size="lg" @click="openDownload">
                <Download class="mr-2 size-4" />
                {{ downloadLabel }}
            </Button>
            <p class="text-xs text-muted-foreground">{{ downloadUrl }}</p>
            <p class="text-sm text-muted-foreground">{{ smartScreen }}</p>
            <p class="text-sm text-muted-foreground">{{ uninstall }}</p>
        </div>
    </div>
</template>
