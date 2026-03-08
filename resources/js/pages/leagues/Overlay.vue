<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import { router } from '@inertiajs/vue3';
import PhantomBadge from '@/components/leagues/PhantomBadge.vue';
import { onMounted, onUnmounted } from 'vue';

defineOptions({ layout: OverlayLayout });

const props = defineProps<{
    league: {
        id: number;
        name: string;
        format: string;
        phantom: boolean;
        wins: number;
        losses: number;
        totalMatches: number;
        deckName: string | null;
        hasActiveMatch: boolean;
    } | null;
}>();

let interval: ReturnType<typeof setInterval>;

onMounted(() => {
    interval = setInterval(() => {
        router.reload({ only: ['league'] });
    }, 5000);
});

onUnmounted(() => {
    clearInterval(interval);
});
</script>

<template>
    <div
        class="flex h-screen flex-col justify-center bg-background/95 px-4 py-3 text-foreground"
        style="-webkit-app-region: drag"
    >
        <template v-if="league">
            <div class="flex items-baseline justify-between">
                <span class="truncate text-sm font-semibold">
                    {{ league.deckName ?? 'Unknown Deck' }}
                </span>
                <span class="ml-2 shrink-0 text-lg font-bold tabular-nums">
                    {{ league.wins }}-{{ league.losses }}
                </span>
            </div>
            <div class="flex items-center justify-between text-xs text-muted-foreground">
                <span class="inline-flex items-center gap-1">
                    {{ league.format }}
                    <PhantomBadge v-if="league.phantom" :label="false" />
                </span>
                <span>
                    Match {{ league.totalMatches + (league.hasActiveMatch ? 0 : 1) }}
                    <span v-if="league.hasActiveMatch" class="ml-1 inline-block size-1.5 animate-pulse rounded-full bg-success" />
                </span>
            </div>
        </template>
        <template v-else>
            <p class="text-center text-xs text-muted-foreground">Start or continue a league to track</p>
        </template>
    </div>
</template>
