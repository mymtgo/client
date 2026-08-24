<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import CoverArtOptionsController from '@/actions/App/Http/Controllers/Decks/CoverArtOptionsController';
import UpdateColorIdentityController from '@/actions/App/Http/Controllers/Decks/UpdateColorIdentityController';
import UpdateCoverArtController from '@/actions/App/Http/Controllers/Decks/UpdateCoverArtController';
import UpdateDeckArchetypeController from '@/actions/App/Http/Controllers/Decks/UpdateDeckArchetypeController';
import DeckDestroyController from '@/actions/App/Http/Controllers/Decks/DestroyController';
import DeckRestoreController from '@/actions/App/Http/Controllers/Decks/RestoreController';
import UpdateNameController from '@/actions/App/Http/Controllers/Decks/UpdateNameController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Input } from '@/components/ui/input';
import { RotateCcw, TriangleAlert, Undo2 } from 'lucide-vue-next';
import type { VersionStats } from '@/types/decks';
import { computed, nextTick, ref, watch } from 'vue';
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

const isReadonly = computed(() => !!props.deck?.deletedAt);
const readonlyTitle = 'Deck deleted — restore it in the danger zone to make changes';

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
const archetypeSearchInput = ref<{ $el: HTMLInputElement } | null>(null);

watch(showArchetypeSelect, (visible) => {
    if (visible) {
        nextTick(() => archetypeSearchInput.value?.$el?.focus());
    }
});

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

const nameDraft = ref(props.deck.name);
const savingName = ref(false);

watch(() => props.deck.name, (name) => {
    nameDraft.value = name;
});

const trimmedName = computed(() => nameDraft.value.trim());
const nameChanged = computed(() => trimmedName.value !== '' && trimmedName.value !== props.deck.name);
const hasCustomName = computed(() => Boolean(props.deck.originalName));

function saveName() {
    if (!nameChanged.value) {
        return;
    }

    savingName.value = true;
    router.patch(
        UpdateNameController.url({ deck: props.deck.id }),
        { name: trimmedName.value },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => { savingName.value = false; },
            onError: () => { nameDraft.value = props.deck.name; },
        },
    );
}

function revertName() {
    if (!props.deck.originalName) {
        return;
    }

    savingName.value = true;
    router.patch(
        UpdateNameController.url({ deck: props.deck.id }),
        { name: props.deck.originalName },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => { savingName.value = false; },
        },
    );
}

const COLOR_OPTIONS: { key: string; label: string }[] = [
    { key: 'W', label: 'White' },
    { key: 'U', label: 'Blue' },
    { key: 'B', label: 'Black' },
    { key: 'R', label: 'Red' },
    { key: 'G', label: 'Green' },
    { key: 'C', label: 'Colorless' },
];

const COLOR_ORDER = COLOR_OPTIONS.map((c) => c.key);

function parseIdentity(value: string | null): string[] {
    if (!value) {
        return [];
    }
    return value.split(',').map((c) => c.trim()).filter(Boolean);
}

const selectedColors = ref<string[]>(parseIdentity(props.deck.colorIdentity));
const savingIdentity = ref(false);

watch(() => props.deck.colorIdentity, (value) => {
    selectedColors.value = parseIdentity(value);
});

function toggleColor(color: string) {
    if (isReadonly.value) {
        return;
    }

    const current = new Set(selectedColors.value);

    if (current.has(color)) {
        current.delete(color);
    } else {
        current.add(color);
    }

    selectedColors.value = Array.from(current).sort(
        (a, b) => COLOR_ORDER.indexOf(a) - COLOR_ORDER.indexOf(b),
    );
}

function normalizeIdentity(colors: string[]): string[] {
    return Array.from(new Set(colors)).sort(
        (a, b) => COLOR_ORDER.indexOf(a) - COLOR_ORDER.indexOf(b),
    );
}

const identityChanged = computed(
    () => normalizeIdentity(selectedColors.value).join(',') !== (props.deck.colorIdentity ?? ''),
);

const previewIdentity = computed(() => {
    const sorted = [...selectedColors.value].sort(
        (a, b) => COLOR_ORDER.indexOf(a) - COLOR_ORDER.indexOf(b),
    );
    return sorted.join(',') || null;
});

function saveIdentity() {
    if (!identityChanged.value) {
        return;
    }

    savingIdentity.value = true;
    router.patch(
        UpdateColorIdentityController.url({ deck: props.deck.id }),
        { color_identity: selectedColors.value },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => { savingIdentity.value = false; },
            onError: () => {
                selectedColors.value = parseIdentity(props.deck.colorIdentity);
            },
        },
    );
}

function resetIdentity() {
    selectedColors.value = parseIdentity(props.deck.colorIdentity);
}

const DELETE_KEYWORD = 'DELETE';

const deleteConfirmation = ref('');
const deleting = ref(false);
const restoring = ref(false);

const canDelete = computed(() => deleteConfirmation.value.trim() === DELETE_KEYWORD);

function deleteDeck() {
    if (!canDelete.value) {
        return;
    }

    deleting.value = true;
    router.delete(DeckDestroyController.url({ deck: props.deck.id }), {
        data: { confirmation: DELETE_KEYWORD },
        onFinish: () => { deleting.value = false; },
    });
}

function restoreDeck() {
    restoring.value = true;
    router.patch(
        DeckRestoreController.url({ deck: props.deck.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoring.value = false;
                deleteConfirmation.value = '';
            },
        },
    );
}
</script>

<template>
    <div class="p-3 lg:p-4">
        <div class="max-w-2xl">
            <Card class="mb-4">
                <CardHeader>
                    <CardTitle>Name</CardTitle>
                    <CardDescription>
                        Rename this deck. The original MTGO name is kept so you can revert at any time.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input
                            v-model="nameDraft"
                            maxlength="255"
                            placeholder="Deck name"
                            class="sm:flex-1"
                            :disabled="isReadonly || savingName"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @keydown.enter.prevent="saveName"
                        />
                        <Button
                            :disabled="!nameChanged || savingName || isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @click="saveName"
                        >
                            <Spinner v-if="savingName" class="mr-2 size-4" />
                            Save
                        </Button>
                    </div>
                    <div v-if="hasCustomName" class="flex items-center justify-between rounded-md border border-dashed border-border bg-muted/30 px-3 py-2 text-sm">
                        <span class="text-muted-foreground">
                            Original MTGO name: <span class="font-medium text-foreground">{{ deck.originalName }}</span>
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="savingName || isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @click="revertName"
                        >
                            <RotateCcw class="mr-1 size-3" />
                            Revert
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <Card class="mb-4">
                <CardHeader>
                    <CardTitle>Color Identity</CardTitle>
                    <CardDescription>
                        Override the deck's color identity. Used for filtering and matchup grouping.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="option in COLOR_OPTIONS"
                            :key="option.key"
                            type="button"
                            class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors"
                            :class="[
                                selectedColors.includes(option.key)
                                    ? 'border-primary bg-primary/10 text-foreground'
                                    : 'border-border bg-transparent text-muted-foreground hover:border-muted-foreground hover:text-foreground',
                                isReadonly ? 'cursor-not-allowed opacity-50' : '',
                            ]"
                            :disabled="isReadonly"
                            :title="isReadonly ? readonlyTitle : option.label"
                            :aria-pressed="selectedColors.includes(option.key)"
                            @click="toggleColor(option.key)"
                        >
                            <ManaSymbols :symbols="option.key" />
                            <span>{{ option.label }}</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-3 rounded-md border border-dashed border-border bg-muted/30 px-3 py-2">
                        <span class="text-sm text-muted-foreground">Preview:</span>
                        <ManaSymbols :symbols="previewIdentity" />
                        <span v-if="!previewIdentity" class="text-sm italic text-muted-foreground">No colors selected</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            :disabled="!identityChanged || savingIdentity || isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @click="saveIdentity"
                        >
                            <Spinner v-if="savingIdentity" class="mr-2 size-4" />
                            Save
                        </Button>
                        <Button
                            v-if="identityChanged"
                            variant="ghost"
                            :disabled="savingIdentity || isReadonly"
                            @click="resetIdentity"
                        >
                            Cancel
                        </Button>
                    </div>
                </CardContent>
            </Card>
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
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="isReadonly"
                                :title="isReadonly ? readonlyTitle : undefined"
                                @click="showArchetypeSelect = !showArchetypeSelect"
                            >
                                Change
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="savingArchetype || isReadonly"
                                :title="isReadonly ? readonlyTitle : undefined"
                                @click="clearArchetype"
                            >
                                Remove
                            </Button>
                        </div>
                    </div>
                    <div v-else>
                        <Button
                            variant="outline"
                            :disabled="isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @click="showArchetypeSelect = !showArchetypeSelect"
                        >
                            {{ showArchetypeSelect ? 'Cancel' : 'Set Archetype' }}
                        </Button>
                    </div>

                    <div v-if="showArchetypeSelect" class="flex flex-col gap-2">
                        <Input
                            ref="archetypeSearchInput"
                            v-model="archetypeSearch"
                            placeholder="Search archetypes..."
                            :disabled="isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                        />
                        <div class="max-h-60 overflow-y-auto space-y-0.5 rounded-md border border-border p-1">
                            <Button
                                v-for="archetype in filteredArchetypes"
                                :key="archetype.id"
                                variant="ghost"
                                class="w-full justify-between"
                                :disabled="savingArchetype || isReadonly"
                                :title="isReadonly ? readonlyTitle : undefined"
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
                    <Select v-model="selectedCardName" :disabled="isReadonly">
                        <SelectTrigger
                            class="w-full"
                            :title="isReadonly ? readonlyTitle : undefined"
                        >
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

                    <div
                        v-if="artOptions.length > 1"
                        class="flex flex-wrap gap-3"
                        :class="isReadonly ? 'pointer-events-none opacity-50' : ''"
                        :title="isReadonly ? readonlyTitle : undefined"
                    >
                        <button
                            v-for="option in artOptions"
                            :key="option.id"
                            type="button"
                            class="overflow-hidden rounded-md border-2 transition-all"
                            :class="selectedCoverId === option.id
                                ? 'border-primary ring-2 ring-primary/30 scale-105'
                                : 'border-border opacity-60 hover:opacity-100 hover:border-muted-foreground'"
                            :disabled="isReadonly"
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
                            :disabled="!hasChanged || saving || isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @click="save"
                        >
                            <Spinner v-if="saving" class="mr-2 size-4" />
                            Save
                        </Button>
                        <Button
                            v-if="coverArt"
                            variant="ghost"
                            :disabled="saving || isReadonly"
                            :title="isReadonly ? readonlyTitle : undefined"
                            @click="clear"
                        >
                            Remove
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <Card class="mt-4 border-destructive/40">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-destructive">
                        <TriangleAlert class="size-4" />
                        Danger zone
                    </CardTitle>
                    <CardDescription>
                        Deleting a deck hides it from your deck list and stops it being updated. Match
                        history is kept, so you can restore the deck at any time.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <template v-if="isReadonly">
                        <p class="text-sm text-muted-foreground">
                            This deck is deleted and read-only. Restore it to make changes again.
                        </p>
                        <div>
                            <Button variant="outline" :disabled="restoring" @click="restoreDeck">
                                <Spinner v-if="restoring" class="mr-2 size-4" />
                                <Undo2 v-else class="mr-2 size-4" />
                                Restore deck
                            </Button>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex flex-col gap-2">
                            <label for="delete-confirmation" class="text-sm text-muted-foreground">
                                Type <span class="font-mono font-medium text-foreground">DELETE</span> to confirm.
                            </label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id="delete-confirmation"
                                    v-model="deleteConfirmation"
                                    autocomplete="off"
                                    placeholder="DELETE"
                                    class="sm:flex-1"
                                    :disabled="deleting"
                                    @keydown.enter.prevent="deleteDeck"
                                />
                                <Button
                                    variant="destructive"
                                    :disabled="!canDelete || deleting"
                                    @click="deleteDeck"
                                >
                                    <Spinner v-if="deleting" class="mr-2 size-4" />
                                    Delete deck
                                </Button>
                            </div>
                        </div>
                    </template>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
