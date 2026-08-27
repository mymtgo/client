<script setup lang="ts">
import { cardFor, type DraftPick, type LimitedCards } from '@/types/limited';
import { Check } from 'lucide-vue-next';

defineProps<{ pick: DraftPick; cards: LimitedCards }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span v-if="pick.reservations.length" class="tracking-wide text-muted-foreground uppercase">Considered</span>
        <template v-for="(reservation, index) in pick.reservations" :key="index">
            <span class="inline-flex items-center gap-1.5 rounded-md border border-black/60 bg-background px-2 py-1">
                <span class="flex size-4 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">{{ index + 1 }}</span>
                {{ cardFor(cards, reservation.catalogId).name }}
                <span v-if="reservation.atSeconds !== null" class="text-muted-foreground">({{ reservation.atSeconds }}s)</span>
            </span>
            <span class="text-muted-foreground" aria-hidden="true">&rarr;</span>
        </template>
        <span
            v-if="pick.pickedCatalogId !== null"
            class="inline-flex items-center gap-1.5 rounded-md border border-primary/60 bg-primary/15 px-2 py-1 text-primary-foreground"
        >
            <Check class="size-3" /> committed<span v-if="pick.elapsedSeconds !== null">&nbsp;{{ pick.elapsedSeconds }}s</span>
        </span>
        <span v-else class="text-muted-foreground italic">not committed</span>
    </div>
</template>
