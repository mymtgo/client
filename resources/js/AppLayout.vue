<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import StatusBar from '@/components/StatusBar.vue';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';

defineProps<{
    title?: string;
    breadcrumbs?: { label: string; href?: string }[];
}>();
</script>

<template>
    <SidebarProvider>
        <AppSidebar />

        <SidebarInset class="flex flex-col">
            <SiteHeader :breadcrumbs="breadcrumbs">
                <template #title>{{ title }}</template>
            </SiteHeader>
            <div class="flex flex-1 flex-col overflow-y-auto">
                <slot />
            </div>
            <StatusBar />
        </SidebarInset>
    </SidebarProvider>
</template>
