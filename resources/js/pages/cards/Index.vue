<script setup lang="ts">
import ArchetypesController from '@/actions/App/Http/Controllers/Cards/ArchetypesController';
import PopulateController from '@/actions/App/Http/Controllers/Cards/PopulateController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import CardTypeFilter from '@/components/cards/CardTypeFilter.vue';
import { CARD_TYPE_KEYS, type CardTypeKey } from '@/components/cards/cardTypes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { Download, ImageOff, Layers, Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Card as UiCard, CardContent } from '@/components/ui/card';

type Card = {
    id: number;
    name: string | null;
    set_code: string | null;
    type: string | null;
    sub_type: string | null;
    oracle_id: string | null;
    image: string | null;
    local_image: string | null;
    image_url: string | null;
    popularity: number;
};

type CardArchetype = {
    id: number;
    name: string;
    format: string | null;
    formatLabel: string | null;
    colorIdentity: string | null;
    maindeck: boolean;
    sideboard: boolean;
    deckCount: number;
};

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    cards: {
        data: Card[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { search: string; format: string; hidden_types: string[]; group_printings: boolean };
    formats: Record<string, string>;
    missingCount: number;
    totalCount: number;
}>();

const ALL = '__all__';

const { add: toast } = useToast();

const search = ref(props.filters.search);
const format = ref(props.filters.format || ALL);
const groupPrintings = ref(props.filters.group_printings);
const typeFilters = ref<Partial<Record<CardTypeKey, boolean>>>(
    Object.fromEntries(CARD_TYPE_KEYS.map((key) => [key, !props.filters.hidden_types.includes(key)])),
);
const [populating, startPopulating] = useSpinGuard();

function applyFilters() {
    const hidden = CARD_TYPE_KEYS.filter((key) => !typeFilters.value[key]);

    router.get(
        '/cards',
        {
            search: search.value || undefined,
            format: format.value === ALL ? undefined : format.value,
            hidden_types: hidden.length ? hidden.join(',') : undefined,
            group_printings: groupPrintings.value ? undefined : 'false',
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

let debounce: ReturnType<typeof setTimeout>;
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(applyFilters, 300);
});
watch([format, groupPrintings], applyFilters);
watch(typeFilters, applyFilters, { deep: true });

function populateNow() {
    const stop = startPopulating();
    router.post(
        PopulateController.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toast({ type: 'success', title: 'Populated', message: 'Missing card data fetched.', duration: 2000 }),
            onFinish: stop,
        },
    );
}

// ── Archetype slide-out ───────────────────────────────────────────────────────

const sheetOpen = ref(false);
const selectedCard = ref<Card | null>(null);
const archetypesLoading = ref(false);
const archetypes = ref<CardArchetype[]>([]);

function groupKey(card: Card): string {
    return card.oracle_id ?? `id:${card.id}`;
}

async function openCard(card: Card) {
    selectedCard.value = card;
    sheetOpen.value = true;
    archetypesLoading.value = true;
    archetypes.value = [];

    try {
        const query = format.value === ALL ? undefined : { format: format.value };
        const response = await fetch(ArchetypesController.url({ group: groupKey(card) }, { query }));
        const data = await response.json();
        archetypes.value = data.archetypes ?? [];
    } finally {
        archetypesLoading.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-64">
                <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="search" placeholder="Search cards..." class="pl-8" />
            </div>

            <Select v-model="format">
                <SelectTrigger class="w-40">
                    <SelectValue placeholder="All formats" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All formats</SelectItem>
                    <SelectItem v-for="(label, value) in formats" :key="value" :value="value">
                        {{ label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <CardTypeFilter v-model="typeFilters" />

            <label class="flex items-center gap-2 text-sm text-muted-foreground">
                <Switch v-model="groupPrintings" />
                Group printings
            </label>

            <div class="ml-auto flex items-center gap-3">
                <span class="text-xs text-muted-foreground">
                    {{ totalCount }} cards
                    <template v-if="missingCount > 0">
                        &middot; <span class="text-amber-400">{{ missingCount }} missing data</span>
                    </template>
                </span>

                <Button v-if="missingCount > 0" size="sm" class="h-8" :disabled="populating" @click="populateNow">
                    <Download class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-bounce': populating }" />
                    Fetch missing ({{ missingCount }})
                </Button>
            </div>
        </div>

        <!-- Grid -->
        <div v-if="cards.data.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-8">
            <UiCard
                v-for="card in cards.data"
                :key="card.id"
                class="gap-0 overflow-hidden py-0 transition-colors hover:border-primary/50"
            >
                <CardContent class="p-0">
                    <button
                        type="button"
                        class="group relative block w-full cursor-pointer text-left"
                        @click="openCard(card)"
                    >
                        <div class="aspect-5/7 w-full bg-muted">
                            <img
                                v-if="card.image_url"
                                :src="card.image_url"
                                :alt="card.name ?? 'Unknown card'"
                                loading="lazy"
                                class="h-full w-full object-cover rounded-[10px]"
                            />
                            <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 text-muted-foreground">
                                <ImageOff class="h-6 w-6" />
                                <span class="px-2 text-center text-xs">{{ card.name ?? 'Unknown' }}</span>
                            </div>
                        </div>

                        <!-- Popularity badge -->
                        <Badge
                            v-if="card.popularity > 0"
                            class="absolute top-1.5 right-1.5 gap-1 bg-background/85 text-foreground backdrop-blur"
                            variant="secondary"
                            :title="`Used in ${card.popularity} archetype${card.popularity === 1 ? '' : 's'}`"
                        >
                            <Layers class="h-3 w-3" />
                            {{ card.popularity }}
                        </Badge>
                    </button>

                    <!-- Caption -->
                    <div class="flex items-center justify-between gap-2 px-2 py-1.5">
                        <span class="truncate text-xs font-medium" :title="card.name ?? ''">{{ card.name ?? 'Unknown' }}</span>
                        <span v-if="card.set_code" class="shrink-0 font-mono text-[10px] text-muted-foreground uppercase">
                            {{ card.set_code }}
                        </span>
                    </div>
                </CardContent>
            </UiCard>
        </div>

        <!-- Empty state -->
        <div v-else class="flex flex-col items-center justify-center gap-2 py-20 text-muted-foreground">
            <Search class="h-8 w-8" />
            <p class="text-sm">No cards match your filters.</p>
        </div>

        <!-- Pagination -->
        <div v-if="cards.last_page > 1" class="flex items-center justify-center gap-1">
            <template v-for="link in cards.links" :key="link.label">
                <Button
                    v-if="link.url"
                    variant="outline"
                    size="sm"
                    class="h-7 text-xs"
                    :class="{ 'bg-primary/10 text-primary': link.active }"
                    @click="router.visit(link.url, { preserveScroll: true })"
                    v-html="link.label"
                />
                <span v-else class="px-2 text-xs text-muted-foreground" v-html="link.label" />
            </template>
        </div>

        <!-- Archetype slide-out -->
        <Sheet v-model:open="sheetOpen">
            <SheetContent class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle class="flex items-center gap-3">
                        <img
                            v-if="selectedCard?.image_url"
                            :src="selectedCard.image_url"
                            :alt="selectedCard.name ?? ''"
                            class="h-16 w-auto rounded-md"
                        />
                        <span>{{ selectedCard?.name ?? 'Card' }}</span>
                    </SheetTitle>
                </SheetHeader>

                <div class="flex flex-col gap-2 px-4 pb-4">
                    <!-- Loading skeleton -->
                    <div v-if="archetypesLoading" class="flex flex-col gap-2">
                        <div v-for="n in 4" :key="n" class="h-12 animate-pulse rounded-lg bg-muted" />
                    </div>

                    <!-- Empty -->
                    <p v-else-if="!archetypes.length" class="py-8 text-center text-sm text-muted-foreground">
                        This card hasn't appeared in any tracked archetypes yet.
                    </p>

                    <!-- Archetype list -->
                    <template v-else>
                        <p class="pb-1 text-xs text-muted-foreground">
                            Seen in {{ archetypes.length }} archetype{{ archetypes.length === 1 ? '' : 's' }}
                        </p>
                        <div
                            v-for="archetype in archetypes"
                            :key="archetype.id"
                            class="flex items-center justify-between gap-3 rounded-lg border border-border bg-card px-3 py-2"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <ManaSymbols :symbols="archetype.colorIdentity" class="shrink-0" />
                                    <span class="truncate text-sm font-medium">{{ archetype.name }}</span>
                                </div>
                                <span v-if="archetype.formatLabel" class="text-[11px] text-muted-foreground">
                                    {{ archetype.formatLabel }} &middot; {{ archetype.deckCount }} deck{{ archetype.deckCount === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <div class="flex shrink-0 gap-1">
                                <Badge v-if="archetype.maindeck" variant="secondary">Main</Badge>
                                <Badge v-if="archetype.sideboard" variant="outline">Side</Badge>
                            </div>
                        </div>
                    </template>
                </div>
            </SheetContent>
        </Sheet>
    </div>
</template>
