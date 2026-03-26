<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ManaSymbols from '@/components/ManaSymbols.vue';
import type { VersionStats } from '@/types/decks';
import { ExternalLink, LayoutDashboard, BarChart3, Swords, Trophy as TrophyIcon, ScrollText, List } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
}>();

const realVersions = computed(() => props.versions.filter((v) => v.id !== null));
const selectedVersionKey = ref<string>(String(props.currentVersionId ?? ''));

// When version changes, reload current page with version param
watch(selectedVersionKey, (newVal) => {
    const url = new URL(window.location.href);
    if (newVal && newVal !== String(props.currentVersionId)) {
        url.searchParams.set('version', newVal);
    } else {
        url.searchParams.delete('version');
    }
    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true });
});

const navItems = computed(() => [
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, href: `/decks/${props.deck.id}` },
    { key: 'card-stats', label: 'Card Stats', icon: BarChart3, href: `/decks/${props.deck.id}/card-stats` },
    { key: 'matches', label: 'Matches', icon: Swords, href: `/decks/${props.deck.id}/matches` },
    { key: 'leagues', label: 'Leagues', icon: TrophyIcon, href: `/decks/${props.deck.id}/leagues` },
    { key: 'matchups', label: 'Matchups', icon: ScrollText, href: `/decks/${props.deck.id}/matchups` },
    { key: 'decklist', label: 'Decklist', icon: List, href: `/decks/${props.deck.id}/decklist` },
]);
</script>

<template>
    <div class="flex h-full flex-col border-r border-border bg-muted/20">
        <!-- Deck header -->
        <div class="flex flex-col gap-1.5 border-b border-border px-4 py-4">
            <div class="flex items-center gap-2">
                <h2 class="truncate text-base font-semibold leading-tight">{{ deck.name }}</h2>
                <ManaSymbols v-if="deck.identity" :symbols="deck.identity" class="shrink-0" />
            </div>
            <div class="flex items-center gap-2">
                <Badge variant="outline" class="text-xs">{{ deck.format }}</Badge>
                <span v-if="trophies" class="flex items-center gap-1 text-xs font-medium text-yellow-400">
                    <TrophyIcon class="size-3" />
                    {{ trophies }}
                </span>
            </div>

            <!-- Version selector -->
            <Select v-if="realVersions.length > 1" v-model="selectedVersionKey">
                <SelectTrigger class="mt-1 h-8 text-xs">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="version in realVersions" :key="version.id" :value="String(version.id)">
                        {{ version.label }}
                        <span v-if="version.isCurrent" class="ml-1 text-muted-foreground">&middot; Current</span>
                        <span v-if="version.dateLabel" class="ml-1 text-muted-foreground">&middot; {{ version.dateLabel }}</span>
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Navigation -->
        <nav class="flex flex-1 flex-col gap-0.5 px-2 py-3">
            <Link
                v-for="item in navItems"
                :key="item.key"
                :href="item.href"
                preserve-state
                class="flex items-center gap-3 rounded-r-md px-4 py-3 text-sm font-medium transition-colors"
                :class="currentPage === item.key
                    ? 'border-l-3 border-primary bg-primary/10 text-foreground'
                    : 'border-l-3 border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground'"
            >
                <component :is="item.icon" class="size-4 shrink-0" />
                {{ item.label }}
            </Link>
        </nav>

        <!-- Actions -->
        <div class="flex flex-col gap-1.5 border-t border-border px-4 py-3">
            <button
                @click="router.post(`/decks/${deck.id}/popout`)"
                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
                <ExternalLink class="size-3.5" />
                Popout Deck
            </button>
        </div>
    </div>
</template>
