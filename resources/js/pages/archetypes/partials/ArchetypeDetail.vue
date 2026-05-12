<script setup lang="ts">
import DownloadDecklistController from '@/actions/App/Http/Controllers/Archetypes/DownloadDecklistController';
import EditController from '@/actions/App/Http/Controllers/Archetypes/EditController';
import DestroyController from '@/actions/App/Http/Controllers/Archetypes/DestroyController';
import UnmergeController from '@/actions/App/Http/Controllers/Archetypes/UnmergeController';
import ShowController from '@/actions/App/Http/Controllers/Archetypes/ShowController';
import VariantDecklist from './VariantDecklist.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { router, Link } from '@inertiajs/vue3';
import { RefreshCw, Pencil, Trash2, GitMerge } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import MergeArchetypeDialog from './MergeArchetypeDialog.vue';

const props = defineProps<{
    detail: App.Data.Front.ArchetypeDetailData;
}>();

const downloading = ref(false);
const confirmingDelete = ref(false);
const mergeOpen = ref(false);

const activeVariantId = ref<string>(
    props.detail.decks.length > 0 ? String(props.detail.decks[0].id) : '',
);

watch(
    () => props.detail.decks.map((d) => d.id).join(','),
    () => {
        const ids = props.detail.decks.map((d) => String(d.id));
        if (! ids.includes(activeVariantId.value) && ids.length > 0) {
            activeVariantId.value = ids[0];
        }
    },
);

const activeDeck = computed<App.Data.Front.ArchetypeDeckData | null>(() => {
    return props.detail.decks.find((d) => String(d.id) === activeVariantId.value) ?? null;
});

const activeIndex = computed<number>(() => {
    return props.detail.decks.findIndex((d) => String(d.id) === activeVariantId.value);
});

function unmerge(): void {
    router.post(
        UnmergeController.url({ archetype: props.detail.archetype.id }),
        {},
        { preserveScroll: true },
    );
}
function deleteArchetype() {
    router.delete(DestroyController.url({ archetype: props.detail.archetype.id }));
}

async function downloadDecklist() {
    downloading.value = true;
    try {
        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        await fetch(DownloadDecklistController.url({ archetype: props.detail.archetype.id }), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                'Accept': 'application/json',
            },
        });
        router.reload({
            only: ['detail'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                downloading.value = false;
            },
        });
    } catch {
        downloading.value = false;
    }
}
</script>

<template>
    <div class="relative flex h-full flex-col">
        <!-- Header -->
        <div class="border-b border-black/60 p-4">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-lg font-bold text-foreground">{{ detail.archetype.name }}</h1>
                    <div class="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                        <span v-if="detail.archetype.format">{{ detail.archetype.format }}</span>
                        <span v-if="detail.archetype.format && detail.archetype.colorIdentity">&middot;</span>
                        <ManaSymbols v-if="detail.archetype.colorIdentity" :symbols="detail.archetype.colorIdentity" class="inline-flex" />
                        <span v-if="detail.archetype.isFallback" class="rounded bg-muted px-1.5 py-0.5 text-xs uppercase tracking-wide">System</span>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button
                        v-if="!detail.archetype.isFallback && !detail.archetype.manual && detail.decks.length > 0"
                        variant="outline"
                        size="sm"
                        :disabled="downloading"
                        @click="downloadDecklist"
                    >
                        <RefreshCw class="mr-1.5 size-3.5" :class="{ 'animate-spin': downloading }" />
                        {{ downloading ? 'Re-downloading…' : 'Re-download' }}
                    </Button>
                    <Button
                        v-if="!detail.archetype.isFallback && !detail.archetype.mergedIntoId"
                        variant="outline"
                        size="sm"
                        @click="mergeOpen = true"
                    >
                        <GitMerge class="mr-1.5 size-3.5" />
                        Merge
                    </Button>
                    <Button v-if="!detail.archetype.isFallback" variant="outline" size="sm" as-child>
                        <Link :href="EditController.url({ archetype: detail.archetype.id })">
                            <Pencil class="mr-1.5 size-3.5" />
                            Edit
                        </Link>
                    </Button>
                    <Button
                        v-if="detail.archetype.manual"
                        variant="destructive"
                        size="sm"
                        @click="confirmingDelete = true"
                    >
                        <Trash2 class="mr-1.5 size-3.5" />
                        Delete
                    </Button>
                </div>
            </div>
        </div>

        <!-- Stale notice -->
        <div
            v-if="detail.isStale && !detail.archetype.manual && !detail.archetype.isFallback"
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
            <!-- Merged notice -->
            <div
                v-if="detail.mergedInto"
                class="flex h-full flex-col items-center justify-center gap-3 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    This archetype has been merged into
                    <Link
                        :href="ShowController.url({ archetype: detail.mergedInto.id })"
                        class="underline"
                    >
                        {{ detail.mergedInto.name }}
                    </Link>.
                    Future detections will be attributed to it.
                </p>
                <Button variant="outline" @click="unmerge">Unmerge</Button>
            </div>

            <!-- Fallback description -->
            <div v-else-if="detail.archetype.isFallback" class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <p class="max-w-md text-sm text-muted-foreground">
                    System fallback for unidentified opponent decks. Auto-assigned when no archetype matches.
                </p>
            </div>

            <!-- Not downloaded -->
            <div
                v-else-if="detail.decks.length === 0 && !detail.archetype.manual && !downloading"
                class="flex h-full flex-col items-center justify-center gap-3"
            >
                <p class="text-sm text-muted-foreground">Decklist not yet downloaded</p>
                <Button @click="downloadDecklist">
                    Download Decklist
                </Button>
            </div>

            <!-- Initial download (no existing decks) -->
            <div v-else-if="downloading && detail.decks.length === 0" class="flex h-full flex-col items-center justify-center gap-3">
                <Spinner class="size-5" />
                <p class="text-sm text-muted-foreground">Downloading decklists...</p>
            </div>

            <!-- Variant picker + active variant decklist -->
            <div
                v-else-if="detail.decks.length > 0"
                class="flex flex-col gap-3"
            >
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-muted-foreground" for="variant-picker">
                        Variant
                    </label>
                    <Select v-model="activeVariantId">
                        <SelectTrigger id="variant-picker" class="w-[260px]">
                            <SelectValue placeholder="Pick a variant" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(deck, idx) in detail.decks"
                                :key="deck.id"
                                :value="String(deck.id)"
                            >
                                Variant {{ idx + 1 }} · seen {{ deck.seenCount }}×
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span class="text-xs text-muted-foreground">
                        of {{ detail.decks.length }}
                    </span>
                </div>

                <VariantDecklist
                    v-if="activeDeck"
                    :archetype-id="detail.archetype.id"
                    :archetype-name="detail.archetype.name"
                    :variant-label="`Variant ${activeIndex + 1}`"
                    :deck="activeDeck"
                />
            </div>
            <div v-else class="flex h-full flex-col items-center justify-center gap-3">
                <p class="text-sm text-muted-foreground">No variants downloaded yet.</p>
            </div>
        </div>

        <!-- Delete confirmation -->
        <div
            v-if="confirmingDelete"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
            @click.self="confirmingDelete = false"
        >
            <div class="w-80 rounded-lg border border-black/40 bg-background p-6 shadow-lg">
                <h3 class="text-sm font-semibold text-foreground">Delete Archetype</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Are you sure you want to delete "{{ detail.archetype.name }}"? This cannot be undone.
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <Button variant="outline" size="sm" @click="confirmingDelete = false">Cancel</Button>
                    <Button variant="destructive" size="sm" @click="deleteArchetype">Delete</Button>
                </div>
            </div>
        </div>

        <MergeArchetypeDialog
            v-model:open="mergeOpen"
            :archetype="detail.archetype"
        />
    </div>
</template>

<style scoped>
.fade-enter-active { transition: opacity 0.1s ease; }
.fade-leave-active { transition: opacity 0.05s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
