<script setup lang="ts">
import OpenOverlayController from '@/actions/App/Http/Controllers/Leagues/OpenOverlayController';
import OpenPopoutController from '@/actions/App/Http/Controllers/Decks/OpenPopoutController';
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import StatusBar from '@/components/StatusBar.vue';
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineProps<{
    title?: string;
}>();

onMounted(() => {
    window.Native?.on('App\\Events\\LeagueMatchStarted', () => {
        router.post(OpenOverlayController.url(), {}, { preserveState: true, preserveScroll: true });
    });

    window.Native?.on('App\\Events\\DeckPopoutRequested', (payload: unknown) => {
        const { deckId } = payload as { deckId: number };
        router.post(OpenPopoutController.url({ deck: deckId }), {}, { preserveState: true, preserveScroll: true });
    });
});
</script>

<template>
    <div class="flex h-screen flex-col">
        <AppHeader />
        <AppNav />
        <div class="flex flex-1 flex-col">
            <slot />
        </div>
        <StatusBar />
    </div>
</template>
