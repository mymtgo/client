<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';

defineProps<{
    title?: string;
}>();
</script>

<template>
    <SidebarProvider>
        <AppSidebar />

        <SidebarInset>
            <SiteHeader>
                <template #title>{{ title }}</template>
            </SiteHeader>
            <div class="flex flex-1 flex-col overflow-y-auto">
                <slot />
            </div>
        </SidebarInset>
    </SidebarProvider>
</template>
