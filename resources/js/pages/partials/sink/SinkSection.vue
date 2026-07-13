<script setup lang="ts">
defineProps<{
    id: string;
    title: string;
}>();
</script>

<template>
    <section :id="id" class="scroll-mt-20">
        <h2 class="mb-4 border-b pb-2 text-lg font-semibold tracking-tight">{{ title }}</h2>
        <div class="flex flex-col gap-6">
            <slot />
        </div>
    </section>
</template>
