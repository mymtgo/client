<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { usePage } from '@inertiajs/vue3';

defineProps<{
    back?: string;
    title?: string;
}>();

const page = usePage();
</script>

<template>
    <UApp>
        Hello

        <UButton>Hello</UButton>
        <slot />
    </UApp>
</template>

<style scoped></style>
