<script setup lang="ts">
import { Download } from 'lucide-vue-next';
import { ref, onMounted } from 'vue';

const showFallback = ref(false);

onMounted(() => {
    setTimeout(() => {
        showFallback.value = true;
    }, 10000);
});
</script>

<template>
    <div class="flex h-screen flex-col items-center justify-center gap-4 bg-background">
        <Download :size="48" class="animate-bounce text-primary" />
        <h2 class="text-lg font-semibold">Installing update...</h2>
        <p v-if="!showFallback" class="max-w-sm text-center text-sm text-muted-foreground">
            The app will close and restart automatically.
            This may take a few minutes — please don't reopen the app manually.
        </p>
        <p v-else class="max-w-sm text-center text-sm text-muted-foreground">
            The update is taking longer than expected. Please close the app manually — the update will install on restart.
        </p>
    </div>
</template>
