<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue';

export type GameLogEntry = {
    timestamp: string;
    message: string;
};

const props = defineProps<{
    entries: GameLogEntry[];
    activeTimestamp?: string;
}>();

const logContainer = ref<HTMLElement | null>(null);

// Find the index of the latest log entry at or before the active timestamp
const activeLogIndex = computed(() => {
    if (!props.activeTimestamp || !props.entries.length) return -1;
    let lastIndex = -1;
    for (let i = 0; i < props.entries.length; i++) {
        if (props.entries[i].timestamp <= props.activeTimestamp) {
            lastIndex = i;
        }
    }
    return lastIndex;
});

// Auto-scroll to the active log entry when it changes
watch(activeLogIndex, async (index) => {
    if (index < 0 || !logContainer.value) return;
    await nextTick();
    const el = logContainer.value.querySelector(`[data-log-index="${index}"]`);
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="border-b bg-muted/50 px-3 py-2">
            <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Game Log</span>
        </div>
        <div v-if="entries.length" ref="logContainer" class="flex-1 overflow-y-auto p-2">
            <div
                v-for="(entry, i) in entries"
                :key="i"
                :data-log-index="i"
                class="border-b border-border/50 px-1 py-1 text-xs last:border-0"
                :class="i === activeLogIndex ? 'bg-primary/10 font-medium' : 'text-muted-foreground'"
            >
                <span class="mr-1.5 font-mono text-[10px] opacity-60">{{ entry.timestamp }}</span>
                <span>{{ entry.message }}</span>
            </div>
        </div>
        <div v-else class="flex flex-1 items-center justify-center text-xs text-muted-foreground">
            No game log available
        </div>
    </div>
</template>
