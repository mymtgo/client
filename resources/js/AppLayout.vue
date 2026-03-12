<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import StatusBar from '@/components/StatusBar.vue';
import ToastContainer from '@/components/ToastContainer.vue';
import { useToast } from '@/composables/useToast';
import { onMounted } from 'vue';

defineProps<{
    title?: string;
}>();

const { add } = useToast();

onMounted(() => {
    window.Native?.on('App\\Events\\AppNotification', (payload: { type: string; title: string; message: string; route?: string }) => {
        add({
            type: payload.type,
            title: payload.title,
            message: payload.message,
            route: payload.route,
        });
    });
});
</script>

<template>
    <div class="flex h-screen flex-col">
        <AppHeader />
        <AppNav />
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
            <slot />
        </div>
        <StatusBar />
        <ToastContainer />
    </div>
</template>
