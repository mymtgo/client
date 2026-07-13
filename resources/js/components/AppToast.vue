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
    error: 'var(--loss)',
    success: 'var(--win)',
    match_win: 'var(--win)',
    match_loss: 'var(--loss)',
    match_voided: 'var(--dim)',
    match_incomplete: 'var(--warn)',
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
        class="relative flex w-80 overflow-hidden rounded-md border border-border bg-muted shadow-overlay transition-all duration-300 ease-out"
        :class="[
            visible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0',
            toast.route ? 'cursor-pointer' : '',
        ]"
        @mouseenter="onMouseEnter"
        @mouseleave="onMouseLeave"
        @click="onClick"
    >
        <!-- Semantic accent bar — same anatomy as Alert's 3px left border. -->
        <div class="w-[3px] shrink-0" :style="{ backgroundColor: accentColor[toast.type] ?? 'var(--primary)' }" />

        <div class="flex flex-1 items-start gap-3 px-3.5 py-3">
            <div class="min-w-0 flex-1">
                <p class="text-[13px] font-semibold text-foreground">{{ toast.title }}</p>
                <p v-if="toast.message" class="mt-0.5 text-[12.5px] text-muted-foreground">{{ toast.message }}</p>
            </div>

            <button class="shrink-0 rounded p-0.5 text-dim transition-colors hover:text-foreground" @click="onClose">
                <X class="size-3.5" />
            </button>
        </div>
    </div>
</template>
