<script setup lang="ts">
import CardThumb from '@/components/limited/CardThumb.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
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
    <Card class="flex flex-col gap-0 p-0 divide-y gap-2">
        <CardContent v-for="[pack, list] in packs" :key="pack" class="p-2">
            <div class="flex w-32 shrink-0 items-center text-xs gap-2">
                <component v-if="list[0] && list[0].direction !== null" :is="list[0].direction === 1 ? ArrowRight : ArrowLeft" class="size-3" />
                <span class="font-semibold">Pack {{ pack }}</span>
            </div>
            <div class="grow grid grid-cols-14 gap-2 mt-1">
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
                        :highlighted="pick.ordinal === selected"
                        :dimmed="pick.pickedCatalogId === null"
                    >
                        <span v-if="pick.note" class="absolute top-0.5 right-0.5 size-1.5 rounded-full bg-yellow-400" />
                    </CardThumb>
                    <span class="block text-center text-[11px] text-muted-foreground">p{{ pick.pickNumber }}</span>
                </button>
            </div>
        </CardContent>
    </Card>
</template>
