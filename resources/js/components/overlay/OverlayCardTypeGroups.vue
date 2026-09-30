<script setup lang="ts" generic="T extends { mtgoId: number | null; name: string; type: string; artCrop: string | null; quantity: number }">
import OverlayCardRow from '@/components/overlay/OverlayCardRow.vue';
import { groupByType } from '@/composables/useCardTypeGroups';
import { computed } from 'vue';

/**
 * Overlay cards under type headings (Creature, Instant, ... Land), each with
 * its copy total, in the order the caller passed them within a type.
 */
const props = defineProps<{
    cards: T[];
}>();

const emit = defineEmits<{
    enter: [card: T, event: MouseEvent];
    leave: [];
}>();

const groups = computed<Record<string, T[]>>(() => groupByType(props.cards, (card) => card.type));

const groupCount = (cards: T[]): number => cards.reduce((sum, card) => sum + card.quantity, 0);
</script>

<template>
    <div>
        <div v-for="(cards, type) in groups" :key="type">
            <h3 class="border-y py-2 pr-1.5 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase">
                {{ type }} ({{ groupCount(cards) }})
            </h3>
            <div class="divide-y text-xs">
                <OverlayCardRow
                    v-for="card in cards"
                    :key="card.mtgoId ?? card.name"
                    :name="card.name"
                    :count="card.quantity"
                    :art-crop="card.artCrop"
                    @preview-enter="emit('enter', card, $event)"
                    @preview-leave="emit('leave')"
                />
            </div>
        </div>
    </div>
</template>
