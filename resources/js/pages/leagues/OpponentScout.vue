<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import OpponentScoutComponent, { type OpponentData } from '@/components/leagues/OpponentScout.vue';
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineOptions({ layout: OverlayLayout });

defineProps<{
    opponent: OpponentData | null;
}>();

onMounted(() => {
    window.Native?.on('App\\Events\\GameCardsSnapshotChanged', () => {
        router.reload({ only: ['opponent'] });
    });
});
</script>

<template>
    <div class="flex h-screen flex-col bg-background text-foreground">
        <div v-if="opponent" class="shrink-0 border-b border-border" style="-webkit-app-region: drag">
            <OpponentScoutComponent :opponent="opponent" />
        </div>
    </div>
</template>
