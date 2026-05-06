<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ManaSymbols from '@/components/ManaSymbols.vue';
import EditableDeckName from '@/components/decks/EditableDeckName.vue';
import DashboardController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import CardStatsController from '@/actions/App/Http/Controllers/Decks/CardStatsController';
import GameStatsController from '@/actions/App/Http/Controllers/Decks/GameStatsController';
import MatchesController from '@/actions/App/Http/Controllers/Decks/MatchesController';
import LeaguesController from '@/actions/App/Http/Controllers/Decks/LeaguesController';
import TournamentsController from '@/actions/App/Http/Controllers/Decks/TournamentsController';
import MatchupsController from '@/actions/App/Http/Controllers/Decks/MatchupsController';
import DecklistController from '@/actions/App/Http/Controllers/Decks/DecklistController';
import SettingsController from '@/actions/App/Http/Controllers/Decks/SettingsController';
import OpenPopoutController from '@/actions/App/Http/Controllers/Decks/OpenPopoutController';
import type { VersionStats } from '@/types/decks';
import { ExternalLink, LayoutDashboard, BarChart3, Boxes, Swords, Trophy as TrophyIcon, Award, ScrollText, List, SettingsIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe?: string;
}>();

const selectableVersions = computed(() => props.versions);
const selectedVersionKey = ref<string>(props.currentVersionId ? String(props.currentVersionId) : '__all__');

// When version changes, reload current page with version param
watch(selectedVersionKey, (newVal) => {
    const url = new URL(window.location.href);
    if (newVal && newVal !== '__all__') {
        url.searchParams.set('version', newVal);
    } else {
        url.searchParams.delete('version');
    }
    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true });
});

const timeframeQuery = computed(() => {
    if (props.timeframe && props.timeframe !== 'alltime') {
        return `?timeframe=${props.timeframe}`;
    }
    return '';
});

const navItems = computed(() => [
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'card-stats', label: 'Card Stats', icon: BarChart3, href: CardStatsController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'game-stats', label: 'Game Stats', icon: Boxes, href: GameStatsController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'matches', label: 'Matches', icon: Swords, href: MatchesController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'leagues', label: 'Leagues', icon: TrophyIcon, href: LeaguesController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'tournaments', label: 'Tournaments', icon: Award, href: TournamentsController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'matchups', label: 'Matchups', icon: ScrollText, href: MatchupsController.url({ deck: props.deck.id }) + timeframeQuery.value },
    { key: 'decklist', label: 'Decklist', icon: List, href: DecklistController.url({ deck: props.deck.id }) },
    { key: 'settings', label: 'Settings', icon: SettingsIcon, href: SettingsController.url({ deck: props.deck.id }) },
]);
</script>

<template>
    <div class="flex h-full flex-col border-r border-black/80 bg-muted/20">
        <!-- Deck header -->
        <div class="relative h-24 overflow-hidden border-b border-black/60">
            <img
                v-if="deck.coverArt"
                :src="deck.coverArt"
                :alt="deck.name"
                class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top opacity-50"
            />
            <div
                class="relative flex h-full flex-col items-start justify-center gap-1.5 px-4"
                :class="deck.coverArt ? '[text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]' : ''"
            >
                <ManaSymbols v-if="deck.colorIdentity" :symbols="deck.colorIdentity" class="shrink-0" />
                <EditableDeckName
                    :deck-id="deck.id"
                    :name="deck.name"
                    :original-name="deck.originalName"
                    :deleted-at="deck.deletedAt"
                    class="w-full"
                />
            </div>
        </div>

        <!-- Deck meta + version selector -->
        <div class="flex flex-col gap-2 border-b border-black/80 px-3 py-3">
            <div class="flex items-center gap-2">
                <span class="rounded-md border border-black/70 bg-background px-2.5 py-1 text-xs font-medium">
                    {{ deck.format }}
                </span>
                <span class="flex items-center gap-1 rounded-md border border-black/70 bg-background px-2.5 py-1 text-xs font-medium text-yellow-400">
                    <TrophyIcon class="size-3" />
                    {{ trophies }}
                </span>
            </div>
            <Select v-if="selectableVersions.length > 2" v-model="selectedVersionKey">
                <SelectTrigger class="h-8 w-full text-xs">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="version in selectableVersions" :key="String(version.id ?? '__all__')" :value="String(version.id ?? '__all__')">
                        {{ version.label }}
                        <span v-if="version.isCurrent" class="ml-1 text-muted-foreground">&middot; Current</span>
                        <span v-if="version.dateLabel" class="ml-1 text-muted-foreground">&middot; {{ version.dateLabel }}</span>
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Navigation -->
        <nav class="flex flex-1 flex-col gap-0.5 border-t border-white/5 px-2 py-3">
            <Link
                v-for="item in navItems"
                :key="item.key"
                :href="item.href"
                prefetch
                preserve-state
                class="flex items-center gap-3 rounded px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    currentPage === item.key
                        ? 'border border-black/50 bg-black/10 text-foreground shadow-inner shadow-black/50 outline outline-white/5'
                        : 'border border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                "
            >
                <component
                    :is="item.icon"
                    class="size-4 shrink-0 transition-[color,filter] duration-150"
                    :class="{ 'nav-icon-active': currentPage === item.key }"
                />
                <span class="shadow-red-500 text-shadow-md">{{ item.label }}</span>
            </Link>
        </nav>

        <!-- Actions -->
        <div class="flex flex-col gap-1.5 border-t border-border px-4 py-3">
            <button
                @click="router.post(OpenPopoutController.url({ deck: deck.id }))"
                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
                <ExternalLink class="size-3.5" />
                Popout Deck
            </button>
        </div>
    </div>
</template>

<style scoped>
.nav-icon-active {
    color: #38bdf8;
    filter: drop-shadow(0 0 4px rgba(56, 189, 248, 0.7)) drop-shadow(0 0 8px rgba(56, 189, 248, 0.35));
}
</style>
