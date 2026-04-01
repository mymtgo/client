<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import CoverArtOptionsController from '@/actions/App/Http/Controllers/Decks/CoverArtOptionsController';
import UpdateCoverArtController from '@/actions/App/Http/Controllers/Decks/UpdateCoverArtController';
import UpdateDeckArchetypeController from '@/actions/App/Http/Controllers/Decks/UpdateDeckArchetypeController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Input } from '@/components/ui/input';
import type { VersionStats } from '@/types/decks';
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    coverArt: (App.Data.Front.CardData & { id: number }) | null;
    cardNames: string[];
    archetypes: App.Data.Front.ArchetypeData[];
}>();

type ArtOption = {
    id: number;
    name: string;
    set_name: string | null;
    set_code: string | null;
    art_crop: string;
};

const selectedCardName = ref<string>('');
const artOptions = ref<ArtOption[]>([]);
const selectedCoverId = ref<number | null>(props.coverArt?.id ?? null);
const loadingOptions = ref(false);
const saving = ref(false);

if (props.coverArt?.name) {
    selectedCardName.value = props.coverArt.name;
}

watch(selectedCardName, async (name) => {
    if (!name) {
        artOptions.value = [];
        selectedCoverId.value = null;
        return;
    }

    loadingOptions.value = true;

    try {
        const url = CoverArtOptionsController.url({ deck: props.deck.id }) + `?card_name=${encodeURIComponent(name)}`;
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data: ArtOption[] = await response.json();
        artOptions.value = data;

        if (data.length === 1) {
            selectedCoverId.value = data[0].id;
        } else if (!data.find(o => o.id === selectedCoverId.value)) {
            selectedCoverId.value = null;
        }
    } finally {
        loadingOptions.value = false;
    }
});

if (props.coverArt?.name) {
    const url = CoverArtOptionsController.url({ deck: props.deck.id }) + `?card_name=${encodeURIComponent(props.coverArt.name)}`;
    fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(r => r.json())
    .then((data: ArtOption[]) => {
        artOptions.value = data;
    });
}

const hasChanged = computed(() => selectedCoverId.value !== (props.coverArt?.id ?? null));

function save() {
    saving.value = true;
    router.patch(
        UpdateCoverArtController.url({ deck: props.deck.id }),
        { cover_id: selectedCoverId.value },
        {
            preserveScroll: true,
            onFinish: () => { saving.value = false; },
        },
    );
}

function clear() {
    saving.value = true;
    router.patch(
        UpdateCoverArtController.url({ deck: props.deck.id }),
        { cover_id: null },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                selectedCardName.value = '';
                selectedCoverId.value = null;
                artOptions.value = [];
            },
        },
    );
}

const selectedArt = computed(() => artOptions.value.find(o => o.id === selectedCoverId.value));

const archetypeSearch = ref('');
const showArchetypeSelect = ref(false);
const savingArchetype = ref(false);

const filteredArchetypes = computed(() => {
    if (!archetypeSearch.value) return props.archetypes;
    const q = archetypeSearch.value.toLowerCase();
    return props.archetypes.filter((a) => a.name.toLowerCase().includes(q));
});

function selectArchetype(archetypeId: number) {
    savingArchetype.value = true;
    router.patch(
        UpdateDeckArchetypeController.url({ deck: props.deck.id }),
        { archetype_id: archetypeId },
        {
            preserveScroll: true,
            onFinish: () => {
                savingArchetype.value = false;
                showArchetypeSelect.value = false;
                archetypeSearch.value = '';
            },
        },
    );
}

function clearArchetype() {
    savingArchetype.value = true;
    router.patch(
        UpdateDeckArchetypeController.url({ deck: props.deck.id }),
        { archetype_id: null },
        {
            preserveScroll: true,
            onFinish: () => { savingArchetype.value = false; },
        },
    );
}
</script>

<template>
    <div class="p-3 lg:p-4">
        <div class="max-w-2xl">
            <Card class="mb-4">
                <CardHeader>
                    <CardTitle>Archetype</CardTitle>
                    <CardDescription>Set the archetype for this deck. This is used when reporting matches.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div v-if="deck.archetype" class="flex items-center justify-between rounded-md border border-border bg-muted/30 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="font-medium">{{ deck.archetype.name }}</span>
                            <ManaSymbols v-if="deck.archetype.colorIdentity" :symbols="deck.archetype.colorIdentity" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Button variant="outline" size="sm" @click="showArchetypeSelect = !showArchetypeSelect">
                                Change
                            </Button>
                            <Button variant="ghost" size="sm" :disabled="savingArchetype" @click="clearArchetype">
                                Remove
                            </Button>
                        </div>
                    </div>
                    <div v-else>
                        <Button variant="outline" @click="showArchetypeSelect = !showArchetypeSelect">
                            {{ showArchetypeSelect ? 'Cancel' : 'Set Archetype' }}
                        </Button>
                    </div>

                    <div v-if="showArchetypeSelect" class="flex flex-col gap-2">
                        <Input v-model="archetypeSearch" placeholder="Search archetypes..." />
                        <div class="max-h-60 overflow-y-auto space-y-0.5 rounded-md border border-border p-1">
                            <Button
                                v-for="archetype in filteredArchetypes"
                                :key="archetype.id"
                                variant="ghost"
                                class="w-full justify-between"
                                :disabled="savingArchetype"
                                @click="selectArchetype(archetype.id)"
                            >
                                <span class="flex-1 text-left">{{ archetype.name }}</span>
                                <ManaSymbols v-if="archetype.colorIdentity" :symbols="archetype.colorIdentity" />
                            </Button>
                            <p v-if="filteredArchetypes.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                                No archetypes found.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Cover Art</CardTitle>
                    <CardDescription>Choose a card from your deck to use as cover art.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <Select v-model="selectedCardName">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a card..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="name in cardNames" :key="name" :value="name">
                                {{ name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <div v-if="loadingOptions" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner class="size-4" />
                        Loading art options...
                    </div>

                    <div v-if="artOptions.length > 1" class="flex flex-wrap gap-3">
                        <button
                            v-for="option in artOptions"
                            :key="option.id"
                            type="button"
                            class="overflow-hidden rounded-md border-2 transition-all"
                            :class="selectedCoverId === option.id
                                ? 'border-primary ring-2 ring-primary/30 scale-105'
                                : 'border-border opacity-60 hover:opacity-100 hover:border-muted-foreground'"
                            @click="selectedCoverId = option.id"
                        >
                            <img
                                :src="option.art_crop"
                                :alt="option.name"
                                class="h-20 w-28 object-cover"
                            />
                        </button>
                    </div>

                    <div v-if="selectedArt" class="max-w-sm overflow-hidden rounded-lg border border-border">
                        <img
                            :src="selectedArt.art_crop"
                            :alt="selectedArt.name"
                            class="w-full object-cover"
                        />
                    </div>

                    <div v-if="selectedCardName" class="flex items-center gap-2">
                        <Button
                            :disabled="!hasChanged || saving"
                            @click="save"
                        >
                            <Spinner v-if="saving" class="mr-2 size-4" />
                            Save
                        </Button>
                        <Button
                            v-if="coverArt"
                            variant="ghost"
                            :disabled="saving"
                            @click="clear"
                        >
                            Remove
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
