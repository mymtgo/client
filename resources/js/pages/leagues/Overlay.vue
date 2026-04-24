<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import { usePoll } from '@inertiajs/vue3';

defineOptions({ layout: OverlayLayout });

defineProps<{
    league: LeagueData | null;
}>();

usePoll(5000, { only: ['league'] });
</script>

<template>
    <div class="h-screen" style="-webkit-app-region: drag">
        <LeagueTracker :league="league" />
    </div>
</template>
