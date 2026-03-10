<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import type { OpponentData } from '@/components/leagues/OpponentScout.vue';
import OpponentScoutComponent from '@/components/leagues/OpponentScout.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    opponent: OpponentData | null;
}>();

let interval: ReturnType<typeof setInterval>;

onMounted(() => {
    interval = setInterval(() => {
        router.reload({ only: ['opponent'] });
    }, 5000);
});

onUnmounted(() => {
    clearInterval(interval);
});
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <div class="flex h-full flex-col">
            <OpponentScoutComponent v-if="opponent" :opponent="opponent" />
            <div v-else class="flex items-center justify-center px-4 py-2 text-center text-sm opacity-50" style="color: #ffffff; background-color: #1a1a1a">
                Waiting for match...
            </div>
        </div>
    </div>
</template>
