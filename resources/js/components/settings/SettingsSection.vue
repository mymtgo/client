<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section class="flex flex-col gap-4 py-6 first:pt-0 last:pb-0">
        <header class="flex flex-col gap-0.5">
            <h2 class="text-base font-semibold text-foreground">{{ title }}</h2>
            <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
        </header>
        <div class="flex flex-col gap-4">
            <slot />
        </div>
    </section>
</template>
