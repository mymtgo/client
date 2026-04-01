<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import IndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ManaSymbols from '@/components/ManaSymbols.vue';
import WinRateBar from '@/components/WinRateBar.vue';
import { router } from '@inertiajs/vue3';
import { ArrowUpDown, Layers, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Paginator<T> = { data: T[]; total: number; per_page: number; current_page: number };

const props = defineProps<{
    decks: Paginator<App.Data.Front.DeckData>;
    formats: Record<string, string>;
    filters: { format: string; search: string; sort: string };
}>();

const searchInput = ref(props.filters.search);
const activeFormat = ref(props.filters.format || 'all');
const sortBy = ref(props.filters.sort);

const sortLabel = computed(
    () =>
        ({
            lastPlayed: 'Last Played',
            winRate: 'Win Rate',
            matchCount: 'Match Count',
            name: 'Name',
        })[sortBy.value] ?? 'Last Played',
);

function applyFilters(page = 1) {
    router.get(
        IndexController.url(),
        {
            format: activeFormat.value !== 'all' ? activeFormat.value : undefined,
            search: searchInput.value || undefined,
            sort: sortBy.value !== 'lastPlayed' ? sortBy.value : undefined,
            page: page > 1 ? page : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(searchInput, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

watch([activeFormat, sortBy], () => {
    applyFilters();
});

function updatePage(page: number) {
    applyFilters(page);
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Empty state -->
        <div v-if="!decks.total && !filters.search && !filters.format" class="flex flex-col items-center gap-2 py-16 text-center">
            <Layers class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No decks yet</p>
            <p class="text-sm text-muted-foreground">Decks are synced automatically from MTGO once the file watcher is running.</p>
        </div>

        <template v-else>
            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Sort -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="gap-1.5">
                            <ArrowUpDown class="size-3.5" />
                            {{ sortLabel }}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuRadioGroup v-model="sortBy">
                            <DropdownMenuRadioItem value="lastPlayed">Last Played</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="winRate">Win Rate</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="matchCount">Match Count</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="name">Name</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Format dropdown -->
                <Select v-model="activeFormat">
                    <SelectTrigger size="sm" class="w-36 text-xs">
                        <SelectValue placeholder="All Formats" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" class="text-xs">All Formats</SelectItem>
                        <SelectItem v-for="(label, raw) in formats" :key="raw" :value="raw" class="text-xs">
                            {{ label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <!-- Search -->
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        v-model="searchInput"
                        placeholder="Search decks..."
                        class="h-8 w-48 py-0 pl-7 text-xs"
                    />
                </div>

                <!-- Pagination (top) -->
                <Pagination
                    v-if="decks.total > decks.per_page"
                    class="mx-0 ml-auto w-auto"
                    @update:page="updatePage"
                    v-slot="{ page }"
                    :items-per-page="decks.per_page"
                    :total="decks.total"
                    :default-page="decks.current_page"
                >
                    <PaginationContent v-slot="{ items }">
                        <PaginationPrevious />
                        <template v-for="(item, index) in items" :key="index">
                            <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page">
                                {{ item.value }}
                            </PaginationItem>
                        </template>
                        <PaginationNext />
                    </PaginationContent>
                </Pagination>
            </div>

            <!-- No results for filters -->
            <div v-if="!decks.total" class="flex flex-col items-center gap-2 py-12 text-center">
                <p class="text-sm text-muted-foreground">No decks match your filters.</p>
            </div>

            <template v-else>
                <!-- Deck cards grid -->
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Card
                        v-for="deck in decks.data"
                        :key="deck.id"
                        class="relative cursor-pointer overflow-hidden transition-colors hover:bg-black/20"
                        @click="router.visit(ShowController({ deck: deck.id }).url)"
                    >
                        <img
                            v-if="deck.coverArt"
                            :src="deck.coverArt"
                            :alt="deck.name"
                            class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top opacity-50"
                        />
                        <CardContent class="relative flex flex-col gap-3" :class="deck.coverArt ? '[text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]' : ''">
                            <!-- Name + meta -->
                            <div class="flex justify-between gap-1">
                                <div class="flex items-center gap-1.5">
                                    <span class="truncate leading-tight font-semibold">{{ deck.name }}</span>
                                    <ManaSymbols v-if="deck.colorIdentity" :symbols="deck.colorIdentity" class="shrink-0" />
                                </div>
                                <div class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                    <Badge variant="outline" class="py-0 text-xs">{{ deck.format }}</Badge>
                                    <span>·</span>
                                    <span>Last played {{ deck.lastPlayedAtHuman ?? 'never' }}</span>
                                </div>
                            </div>

                            <!-- Stats -->
                            <div class="flex items-end justify-between gap-4">
                                <div class="flex flex-1 flex-col gap-1">
                                    <span class="text-xs text-muted-foreground">win rate</span>
                                    <WinRateBar :winrate="deck.winrate" :solid="!!deck.coverArt" />
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium tabular-nums">{{ deck.matchesCount }} matches</div>
                                    <div class="text-xs text-muted-foreground tabular-nums">
                                        <span>{{ deck.matchesWon }}W</span>
                                        <span class="mx-0.5">-</span>
                                        <span class="text-destructive">{{ deck.matchesLost }}L</span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Pagination (bottom) -->
                <Pagination
                    v-if="decks.total > decks.per_page"
                    class="justify-end"
                    @update:page="updatePage"
                    v-slot="{ page }"
                    :items-per-page="decks.per_page"
                    :total="decks.total"
                    :default-page="decks.current_page"
                >
                    <PaginationContent v-slot="{ items }">
                        <PaginationPrevious />
                        <template v-for="(item, index) in items" :key="index">
                            <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page">
                                {{ item.value }}
                            </PaginationItem>
                        </template>
                        <PaginationNext />
                    </PaginationContent>
                </Pagination>
            </template>
        </template>
    </div>
</template>
