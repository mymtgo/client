<script setup lang="ts">
import DeckSidebar from '@/components/decks/DeckSidebar.vue';
import SettingsController from '@/actions/App/Http/Controllers/Decks/SettingsController';
import { Link } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import type { VersionStats } from '@/types/decks';

const props = defineProps<{
    deck?: App.Data.Front.DeckData;
    versions?: VersionStats[];
    currentVersionId: number | null;
    trophies?: number;
    currentPage: string;
    timeframe?: string;
}>();
</script>

<template>
    <div v-if="deck" class="flex min-h-0 flex-1 flex-col">
        <div
            v-if="deck.deletedAt"
            class="flex items-center justify-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-4 py-1.5 text-xs text-amber-200"
        >
            <AlertTriangle class="size-3.5 shrink-0 text-amber-400" />
            <span>This deck has been deleted on MTGO. Historical match data is preserved.</span>
        </div>
        <Link
            v-if="!deck.archetype"
            :href="SettingsController.url({ deck: deck.id })"
            class="flex items-center justify-center gap-2 border-b border-white/5 bg-white/5 px-4 py-1.5 text-xs text-white transition hover:bg-white/10"
        >
            <AlertTriangle class="size-3.5 shrink-0 text-amber-400" />
            <span>This deck is an unknown archetype. Click here to set it.</span>
        </Link>
        <div class="flex min-h-0 flex-1">
            <div class="w-56 shrink-0">
                <DeckSidebar
                    :deck="deck"
                    :versions="versions"
                    :current-version-id="currentVersionId"
                    :trophies="trophies"
                    :current-page="currentPage"
                    :timeframe="timeframe"
                />
            </div>
            <div class="flex min-h-0 flex-1 flex-col border-l border-white/5 overflow-y-auto">
                <slot />
            </div>
        </div>
    </div>
</template>
