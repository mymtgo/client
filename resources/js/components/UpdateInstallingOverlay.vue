<script setup lang="ts">
import { Download } from 'lucide-vue-next';
import { useInstallUpdate } from '@/composables/useInstallUpdate';

const { installing } = useInstallUpdate();
</script>

<template>
    <Teleport to="body">
        <div v-if="installing" class="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-4 bg-background">
            <Download :size="48" class="animate-bounce text-primary" />
            <h2 class="text-lg font-semibold">Installing update...</h2>
            <p class="max-w-sm text-center text-sm text-muted-foreground">
                The app will close and restart automatically. This may take a few minutes, so please don't reopen the app manually.
            </p>
        </div>
    </Teleport>
</template>
