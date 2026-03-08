<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import StatusBar from '@/components/StatusBar.vue';

defineProps<{
    title?: string;
}>();
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
