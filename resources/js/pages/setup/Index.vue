<script setup lang="ts">
defineProps<{
    logPath: string;
    dataPath: string;
    logPathStatus: { valid: boolean; fileCount: number; message: string };
    dataPathStatus: { valid: boolean; fileCount: number; message: string };
    archetypeCount: number;
    deckCount: number;
    setupSkippedArchetypes: boolean;
    setupSkippedDecks: boolean;
}>();
</script>

<template>
    <div>
        <slot />
    </div>
</template>
