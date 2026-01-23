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
    <SidebarProvider>
        <AppSidebar variant="inset" />

        <SidebarInset>
            <SiteHeader>
                <template #title> {{ title }} </template>
            </SiteHeader>
            <div class="flex flex-1 flex-col">
                <div class="@container/main flex flex-1 flex-col gap-2">
                    <div class="flex flex-col gap-4 py-4 md:gap-6 md:py-6">
                        <div class="px-4 lg:px-6">
                            <slot />
                        </div>
                    </div>
                </div>
            </div>
        </SidebarInset>
    </SidebarProvider>
</template>

<style scoped></style>
