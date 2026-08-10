<script setup lang="ts">
import OverlayLayout from '@/Layouts/OverlayLayout.vue';

defineOptions({ layout: OverlayLayout });

/**
 * Placeholder page component for the game overlay window.
 *
 * TODO(task 13): replace this with the real overlay layout and sub-components
 * (opponent panel, draw odds, sideboard guide, archetype notes). This stub
 * exists only so `Inertia::render('overlay/GameOverlay', ...)` resolves to a
 * component file during Task 9 — the window and controller assembly task —
 * ahead of the page build.
 */
defineProps<{
    sections: {
        opponent: boolean;
        drawOdds: boolean;
        sideboard: boolean;
    };
    opponent: App.Data.Front.OverlayOpponentData | null;
    archetypes: App.Data.Front.ArchetypeData[];
    drawOdds: App.Data.Front.DrawOddsData | null;
    sideboard: App.Data.Front.SideboardGuideData | null;
    notes: {
        current: App.Data.Front.ArchetypeNoteData[];
        other: App.Data.Front.ArchetypeNoteData[];
    };
    isSideboarding: boolean;
}>();
</script>

<template>
    <div class="flex h-screen flex-col bg-background text-foreground" />
</template>
