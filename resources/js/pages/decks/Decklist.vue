<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import type { VersionStats, VersionDecklist } from '@/types/decks';
import { computed, ref } from 'vue';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
    versionDecklists: Record<string, VersionDecklist>;
}>();

const selectedVersionKey = ref<string>(String(props.currentVersionId ?? ''));

const activeDecklist = computed((): VersionDecklist => {
    return props.versionDecklists?.[selectedVersionKey.value] ?? { maindeck: props.maindeck, sideboard: props.sideboard };
});

const decklistOrgUrl = computed(() => {
    const dl = activeDecklist.value;
    const mainCards = Object.values(dl.maindeck).flat().map((c) => `${c.quantity} ${c.name}`).join('\n');
    const sideCards = dl.sideboard.map((c) => `${c.quantity} ${c.name}`).join('\n');
    const params = new URLSearchParams({
        deckmain: mainCards,
        deckside: sideCards,
        eventformat: props.deck.format,
    });
    return `https://decklist.org/?${params.toString()}`;
});
</script>

<template>
    <div class="p-3 lg:p-4">
        <div class="mb-4 flex items-center justify-end">
            <a :href="decklistOrgUrl" target="_blank" class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground">
                Deck Registration
            </a>
        </div>
        <DeckList :maindeck="activeDecklist.maindeck" :sideboard="activeDecklist.sideboard" />
    </div>
</template>
