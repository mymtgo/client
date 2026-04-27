<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import { AlertTriangle, FileQuestion } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    cards: any[] | null;
    incomplete?: boolean;
}>();

const maindeck = computed(() => {
    if (!props.cards) return {};
    const grouped: Record<string, any[]> = {};
    for (const card of props.cards.filter((c: any) => !c.sideboard)) {
        const type = card.type ?? 'Unknown';
        (grouped[type] ??= []).push(card);
    }
    return grouped;
});

const sideboard = computed(() => {
    if (!props.cards) return [];
    return props.cards.filter((c: any) => c.sideboard);
});

const maindeckCount = computed(
    () => props.cards?.filter((c: any) => !c.sideboard).reduce((sum: number, c: any) => sum + c.quantity, 0) ?? 0,
);
const sideboardCount = computed(
    () => props.cards?.filter((c: any) => c.sideboard).reduce((sum: number, c: any) => sum + c.quantity, 0) ?? 0,
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div v-if="!cards" class="flex flex-1 flex-col items-center justify-center gap-3 px-8 text-center">
            <FileQuestion class="size-10 text-muted-foreground/50" />
            <p class="text-sm text-muted-foreground">Upload a .dek file or pick a match to preview cards.</p>
        </div>

        <template v-else>
            <div v-if="incomplete" class="border-b border-black/40 p-4">
                <Alert class="border-amber-500/40 bg-amber-500/10 text-amber-100">
                    <AlertTriangle class="text-amber-400" />
                    <AlertDescription class="text-amber-100/90">
                        Cardlist may be incomplete. Built from cards the opponent revealed during play. Edit the archetype after creation to refine it.
                    </AlertDescription>
                </Alert>
            </div>

            <div class="flex items-center justify-between border-b border-black/40 px-4 py-2.5">
                <span class="text-sm text-muted-foreground">
                    {{ maindeckCount }} cards + {{ sideboardCount }} sideboard
                </span>
            </div>

            <div class="flex-1 overflow-y-auto p-4">
                <DeckList :maindeck="maindeck" :sideboard="sideboard" />
            </div>
        </template>
    </div>
</template>
