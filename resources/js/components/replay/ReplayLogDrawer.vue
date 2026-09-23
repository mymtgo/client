<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { computed, nextTick, useTemplateRef, watch } from 'vue';
import type { ReplayLogItem } from './types';

const props = defineProps<{
    open: boolean;
    items: ReplayLogItem[];
    current: number;
    localId: number | null;
    playerName: (id: number | undefined) => string;
}>();

const emit = defineEmits<{
    close: [];
    seek: [frame: number];
}>();

const list = useTemplateRef<HTMLElement>('list');

const lineCount = computed(() => props.items.filter((item) => item.kind === 'line').length);

/** The latest row at or before the current frame. */
const currentKey = computed(() => props.items.findLast((item) => item.frame <= props.current)?.key ?? null);

function scrollToCurrent(instant: boolean) {
    const box = list.value;

    if (!box || currentKey.value === null) {
        return;
    }

    const row = box.querySelector<HTMLElement>(`[data-log-key="${currentKey.value}"]`);

    if (!row) {
        return;
    }

    const top = row.offsetTop;
    const height = box.clientHeight;

    if (instant || top < box.scrollTop + 30 || top > box.scrollTop + height - 40) {
        box.scrollTo({ top: Math.max(0, top - height / 2), behavior: instant ? 'auto' : 'smooth' });
    }
}

watch(currentKey, () => {
    if (props.open) {
        scrollToCurrent(false);
    }
});

watch(
    () => props.open,
    async (open) => {
        if (open) {
            await nextTick();
            scrollToCurrent(true);
        }
    },
);
</script>

<template>
    <aside
        class="absolute inset-y-0 right-0 z-70 flex w-85 flex-col overflow-hidden border-l border-border bg-card transition-[transform,visibility] duration-200 ease-out"
        :class="open ? 'visible translate-x-0 shadow-[-12px_0_32px_rgba(0,0,0,.28)]' : 'invisible translate-x-[105%]'"
    >
        <div class="flex h-11 flex-none items-center gap-2 border-b border-border pr-2 pl-3.5">
            <span class="text-[11px] font-bold tracking-wider uppercase">Game log</span>
            <span class="text-xs text-muted-foreground">{{ lineCount }} events</span>
            <div class="flex-1" />
            <button
                type="button"
                title="Close log (L)"
                class="grid size-7.5 cursor-pointer place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                @click="emit('close')"
            >
                <X :size="16" />
            </button>
        </div>

        <div ref="list" class="relative min-h-0 flex-1 overflow-y-auto pb-3 select-text">
            <div v-if="!items.length" class="px-3.5 py-6 text-center text-xs text-muted-foreground">No game log was recorded for this game.</div>

            <template v-for="item in items" :key="item.key">
                <button
                    v-if="item.kind === 'header'"
                    type="button"
                    :data-log-key="item.key"
                    class="sticky top-0 z-1 flex w-full cursor-pointer items-center gap-2 bg-card px-3.5 pt-2.25 pb-1.5 text-left text-xs font-bold"
                    @click="emit('seek', item.frame)"
                >
                    <span
                        class="size-1.75 rounded-full"
                        :class="item.player !== null && item.player === localId ? 'bg-primary' : 'bg-muted-foreground'"
                    />
                    <span
                        >{{ item.text }}<template v-if="item.player !== null"> · {{ playerName(item.player) }}</template></span
                    >
                </button>
                <button
                    v-else
                    type="button"
                    :data-log-key="item.key"
                    class="grid w-full cursor-pointer grid-cols-[58px_minmax(0,1fr)] gap-1.5 px-3.5 py-1.25 text-left text-[12.5px] hover:bg-accent"
                    :class="[
                        item.key === currentKey ? 'bg-primary/15 font-semibold' : '',
                        item.frame > current ? 'text-muted-foreground' : 'text-foreground',
                    ]"
                    @click="emit('seek', item.frame)"
                >
                    <span class="pt-px font-mono text-[11px] font-normal text-muted-foreground">{{ item.timestamp }}</span>
                    <span class="text-pretty">{{ item.text }}</span>
                </button>
            </template>
        </div>
    </aside>
</template>
