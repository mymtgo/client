<script setup lang="ts">
// Minimal error surface for the bootstrap/app.php exception handler.
// Restyled with the v1 UI rebuild.
defineProps<{ status: number; message: string }>();
</script>

<template>
    <div class="flex h-screen flex-col items-center justify-center gap-2 bg-neutral-950 text-neutral-400">
        <p class="text-3xl font-semibold text-neutral-200">{{ status }}</p>
        <p class="text-sm">{{ message }}</p>
    </div>
</template>
