<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import OpponentScoutComponent, { type OpponentData } from '@/components/leagues/OpponentScout.vue';
import { usePoll } from '@inertiajs/vue3';

defineOptions({ layout: OverlayLayout });

defineProps<{
    opponent: OpponentData | null;
}>();

// The window is opened once at boot and never re-navigated, so polling has to
// run for the lifetime of the window. Stopping once an archetype resolved left
// the overlay pinned to the first opponent it ever saw for the whole session.
usePoll(5000, { only: ['opponent'] });
</script>

<template>
    <div class="h-screen bg-background text-foreground" style="-webkit-app-region: drag">
        <OpponentScoutComponent v-if="opponent" :opponent="opponent" />
        <div v-else class="flex h-full items-center justify-center px-4 text-center text-sm text-muted-foreground">
            Waiting for match…
        </div>
    </div>
</template>
