<script setup lang="ts">
import CardThumb from '@/components/limited/CardThumb.vue';
import { cardFor, type DraftPick, type LimitedCards } from '@/types/limited';
import { ArrowLeft, ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    picks: DraftPick[];
    cards: LimitedCards;
    selected: number;
}>();
const emit = defineEmits<{ select: [ordinal: number] }>();

const packs = computed<[number, DraftPick[]][]>(() => {
    const groups = new Map<number, DraftPick[]>();
    for (const pick of props.picks) {
        const list = groups.get(pick.packNumber) ?? [];
        list.push(pick);
        groups.set(pick.packNumber, list);
    }
    return [...groups.entries()].sort(([a], [b]) => a - b);
});
</script>

<template>
    <div class="flex flex-col gap-3 rounded-lg border border-black/60 bg-card p-4">
        <div v-for="[pack, list] in packs" :key="pack" class="flex items-start gap-3">
            <div class="flex w-16 shrink-0 flex-col text-xs">
                <span class="font-semibold">Pack {{ pack }}</span>
                <span class="inline-flex items-center gap-1 text-muted-foreground">
                    <component v-if="list[0] && list[0].direction !== null" :is="list[0].direction === 1 ? ArrowRight : ArrowLeft" class="size-3" />
                    <span v-else aria-hidden="true">·</span>
                    pass
                </span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="pick in list"
                    :key="pick.ordinal"
                    type="button"
                    class="relative hover:cursor-pointer rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :aria-current="pick.ordinal === selected"
                    :aria-label="`Pack ${pick.packNumber} pick ${pick.pickNumber}`"
                    @click="emit('select', pick.ordinal)"
                >
                    <CardThumb
                        :card="cardFor(cards, pick.pickedCatalogId ?? 0)"
                        :width="72"
                        :highlighted="pick.ordinal === selected"
                        :dimmed="pick.pickedCatalogId === null"
                    >
                        <span v-if="pick.note" class="absolute top-0.5 right-0.5 size-1.5 rounded-full bg-yellow-400" />
                    </CardThumb>
                    <span class="block text-center text-[11px] text-muted-foreground">p{{ pick.pickNumber }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
