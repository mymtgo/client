<script setup lang="ts">
import AppToast from '@/components/AppToast.vue';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';

const { toasts, remove } = useToast();

function navigate(route: string) {
    router.visit(route);
}
</script>

<template>
    <div class="pointer-events-none fixed inset-0 z-50 flex flex-col items-end justify-end gap-2 p-4">
        <TransitionGroup name="toast">
            <div v-for="toast in toasts" :key="toast.id" class="pointer-events-auto">
                <AppToast :toast="toast" @dismiss="remove" @navigate="navigate" />
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.3s ease;
}

.toast-enter-from {
    opacity: 0;
    transform: translateY(1rem);
}

.toast-leave-to {
    opacity: 0;
    transform: translateX(100%);
}
</style>
