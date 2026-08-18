<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import DonationModal from '@/components/DonationModal.vue';
import StatusBar from '@/components/StatusBar.vue';
import ToastContainer from '@/components/ToastContainer.vue';
import UpdateBanner from '@/components/UpdateBanner.vue';
import { useToast } from '@/composables/useToast';
import { usePage } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';

defineProps<{
    title?: string;
}>();

const { add } = useToast();

const page = usePage<{ flash?: { error?: string | null; success?: string | null } }>();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.error) {
            add({ type: 'error', title: 'Error', message: flash.error });
        }
        if (flash?.success) {
            add({ type: 'success', title: 'Success', message: flash.success });
        }
    },
);

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
        <UpdateBanner />
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
            <slot />
        </div>
        <StatusBar />
        <ToastContainer />
        <DonationModal />
    </div>
</template>
