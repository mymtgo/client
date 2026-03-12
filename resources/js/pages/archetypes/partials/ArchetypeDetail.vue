<script setup lang="ts">
import DownloadDecklistController from '@/actions/App/Http/Controllers/Archetypes/DownloadDecklistController';
import ExportDekController from '@/actions/App/Http/Controllers/Archetypes/ExportDekController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ManaSymbols from '@/components/ManaSymbols.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import { router } from '@inertiajs/vue3';
import { Download, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    detail: App.Data.Front.ArchetypeDetailData;
}>();

const downloading = ref(false);
const exporting = ref(false);

function downloadDecklist() {
    downloading.value = true;
    router.post(
        DownloadDecklistController.url({ archetype: props.detail.archetype.id }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => { downloading.value = false; },
        },
    );
}

function exportDek() {
    exporting.value = true;
    router.post(
        ExportDekController.url({ archetype: props.detail.archetype.id }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => { exporting.value = false; },
        },
    );
}

const maindeck = computed(() => {
    if (!props.detail.cards) return {};
    const grouped: Record<string, App.Data.Front.CardData[]> = {};
    for (const card of props.detail.cards.filter(c => !c.sideboard)) {
        const type = card.type ?? 'Unknown';
        (grouped[type] ??= []).push(card);
    }
    return grouped;
});

const sideboard = computed(() => {
    if (!props.detail.cards) return [];
    return props.detail.cards.filter(c => c.sideboard);
});
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Header -->
        <div class="border-b border-black/60 p-4">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-lg font-bold text-foreground">{{ detail.archetype.name }}</h1>
                    <div class="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                        <span>{{ detail.archetype.format }}</span>
                        <span>&middot;</span>
                        <ManaSymbols v-if="detail.archetype.colorIdentity" :symbols="detail.archetype.colorIdentity" class="inline-flex" />
                    </div>
                </div>
                <Button
                    v-if="detail.archetype.hasDecklist"
                    variant="outline"
                    size="sm"
                    :disabled="exporting"
                    @click="exportDek"
                >
                    <Download class="mr-1.5 size-3.5" />
                    Download .dek
                </Button>
            </div>

            <!-- Winrate stats -->
            <div v-if="detail.playingWinrate !== null || detail.facingWinrate !== null" class="mt-3 flex flex-col gap-1">
                <div v-if="detail.playingWinrate !== null" class="text-sm text-purple-400">
                    {{ detail.playingWinrate }}% winrate playing this archetype
                    <span class="text-muted-foreground">({{ detail.playingRecord }})</span>
                </div>
                <div v-if="detail.facingWinrate !== null" class="text-sm text-orange-400">
                    {{ detail.facingWinrate }}% winrate against this archetype
                    <span class="text-muted-foreground">({{ detail.facingRecord }})</span>
                </div>
            </div>
        </div>

        <!-- Stale notice -->
        <div
            v-if="detail.isStale"
            class="mx-4 mt-3 flex items-center justify-between rounded-md border border-yellow-500/30 bg-yellow-500/10 px-3 py-2"
        >
            <span class="text-sm text-yellow-500">
                This decklist is over a week old. Consider re-downloading in case of changes.
            </span>
            <Button variant="ghost" size="sm" class="text-yellow-500 hover:text-yellow-400" :disabled="downloading" @click="downloadDecklist">
                <RefreshCw class="mr-1.5 size-3.5" />
                Re-download
            </Button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto p-4">
            <!-- Not downloaded -->
            <div v-if="!detail.archetype.hasDecklist && !downloading" class="flex h-full flex-col items-center justify-center gap-3">
                <p class="text-sm text-muted-foreground">Decklist not yet downloaded</p>
                <Button @click="downloadDecklist">
                    Download Decklist
                </Button>
            </div>

            <!-- Downloading -->
            <div v-else-if="downloading" class="flex h-full flex-col items-center justify-center gap-3">
                <Spinner class="size-5" />
                <p class="text-sm text-muted-foreground">Downloading decklist...</p>
            </div>

            <!-- Downloaded -->
            <DeckList v-else-if="detail.cards" :maindeck="maindeck" :sideboard="sideboard" />
        </div>
    </div>
</template>
