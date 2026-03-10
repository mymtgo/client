<script setup lang="ts">
import type { Toast } from '@/composables/useToast';
import { X } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
    toast: Toast;
}>();

const emit = defineEmits<{
    dismiss: [id: number];
    navigate: [route: string];
}>();

const hovered = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
const visible = ref(false);

function startTimer() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => emit('dismiss', props.toast.id), props.toast.duration);
}

function pauseTimer() {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
}

function onMouseEnter() {
    hovered.value = true;
    pauseTimer();
}

function onMouseLeave() {
    hovered.value = false;
    startTimer();
}

function onClick() {
    if (props.toast.route) {
        emit('navigate', props.toast.route);
        emit('dismiss', props.toast.id);
    }
}

function onClose(e: Event) {
    e.stopPropagation();
    emit('dismiss', props.toast.id);
}

const accentColor: Record<string, string> = {
    match_win: '#22c55e',
    match_loss: '#ef4444',
    match_voided: '#6b7280',
    match_incomplete: '#f59e0b',
};

onMounted(() => {
    requestAnimationFrame(() => {
        visible.value = true;
    });
    startTimer();
});

onUnmounted(() => {
    pauseTimer();
});
</script>

<template>
    <div
        class="relative flex w-80 overflow-hidden rounded-lg border border-white/10 shadow-lg transition-all duration-300 ease-out"
        :class="[
            visible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0',
            toast.route ? 'cursor-pointer' : '',
        ]"
        style="background-color: #1e1e2e"
        @mouseenter="onMouseEnter"
        @mouseleave="onMouseLeave"
        @click="onClick"
    >
        <!-- Color accent bar -->
        <div class="w-1 shrink-0" :style="{ backgroundColor: accentColor[toast.type] ?? '#6366f1' }" />

        <div class="flex flex-1 items-start gap-3 px-3 py-3">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-white">{{ toast.title }}</p>
                <p class="mt-0.5 text-xs text-white/60">{{ toast.message }}</p>
            </div>

            <button class="shrink-0 rounded p-0.5 text-white/40 transition-colors hover:text-white/80" @click="onClose">
                <X class="size-3.5" />
            </button>
        </div>
    </div>
</template>
