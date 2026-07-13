<script setup lang="ts">
defineProps<{
    tag?: string;
    note: string;
}>();
</script>

<template>
    <p class="flex flex-wrap items-center gap-2 text-[12.5px] text-dim">
        <span class="rounded-[5px] bg-win-soft px-2 py-0.5 font-mono text-[9.5px] font-semibold tracking-[0.08em] text-win uppercase">
            {{ tag ?? 'resolved' }}
        </span>
        {{ note }}
    </p>
</template>
