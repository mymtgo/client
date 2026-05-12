<script setup lang="ts">
import VariantDestroyController from '@/actions/App/Http/Controllers/Archetypes/Variants/DestroyController';
import ShowController from '@/actions/App/Http/Controllers/Archetypes/ShowController';
import UpdateController from '@/actions/App/Http/Controllers/Archetypes/UpdateController';
import ArchetypeDeckCard from '@/components/archetypes/ArchetypeDeckCard.vue';
import { Button } from '@/components/ui/button';
import ArchetypeForm from '@/pages/archetypes/partials/ArchetypeForm.vue';
import ArchetypeLayout from '@/pages/archetypes/partials/ArchetypeLayout.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    archetypes: {
        data: App.Data.Front.ArchetypeData[];
        current_page: number;
        last_page: number;
    };
    formats: Record<string, string>;
    filters: {
        format: string;
        search: string;
    };
    detail: App.Data.Front.ArchetypeDetailData;
}>();

const confirmingDeleteDeckId = ref<number | null>(null);
const hoveredCard = ref<App.Data.Front.CardData | null>(null);
const previewTop = ref(0);
const previewLeft = ref(0);
const rootRef = ref<HTMLElement | null>(null);

const PREVIEW_W = 200;
const PREVIEW_H = 280;
const GAP = 12;

const canDeleteVariant = computed(() => props.detail.decks.length > 1);

function onCardEnter(card: App.Data.Front.CardData, event: MouseEvent) {
    if (!rootRef.value) return;
    hoveredCard.value = card;
    const rowRect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const rootRect = rootRef.value.getBoundingClientRect();
    const rowLeft = rowRect.left - rootRect.left;
    const rowRight = rowRect.right - rootRect.left;
    const rowTop = rowRect.top - rootRect.top;
    let left = rowRight + GAP;
    if (left + PREVIEW_W > rootRect.width) {
        left = rowLeft - PREVIEW_W - GAP;
    }
    previewLeft.value = Math.max(8, left);
    previewTop.value = Math.max(8, Math.min(rowTop - PREVIEW_H / 2, rootRect.height - PREVIEW_H - 8));
}

function onCardLeave() {
    hoveredCard.value = null;
}

function handleSubmit(data: { name: string; format: string; color_identity: string | null; cards: any[] | null }) {
    const payload: Record<string, unknown> = {
        name: data.name,
        format: data.format,
        color_identity: data.color_identity,
    };
    if (data.cards) {
        payload.cards = data.cards;
    }
    router.put(UpdateController.url({ archetype: props.detail.archetype.id }), payload);
}

function requestDelete(deckId: number) {
    confirmingDeleteDeckId.value = deckId;
}

function confirmDelete() {
    if (confirmingDeleteDeckId.value === null) return;
    router.delete(
        VariantDestroyController.url({
            archetype: props.detail.archetype.id,
            deck: confirmingDeleteDeckId.value,
        }),
        {
            preserveScroll: true,
            onFinish: () => {
                confirmingDeleteDeckId.value = null;
            },
        },
    );
}
</script>

<template>
    <ArchetypeLayout :archetypes="archetypes" :formats="formats" :filters="filters" :selected-id="detail.archetype.id">
        <div ref="rootRef" class="relative flex h-full min-h-0 flex-col p-4">
            <div class="mb-6 border-b border-black/60 pb-4">
                <h1 class="text-lg font-bold text-foreground">Edit {{ detail.archetype.name }}</h1>
            </div>

            <div class="flex min-h-0 flex-1 gap-8">
                <!-- Left: form -->
                <div class="w-72 shrink-0">
                    <ArchetypeForm
                        :name="detail.archetype.name"
                        :format="detail.archetype.format"
                        :color-identity="detail.archetype.colorIdentity"
                        :cancel-href="ShowController.url({ archetype: detail.archetype.id })"
                        submit-label="Save Changes"
                        @submit="handleSubmit"
                    />
                </div>

                <!-- Right: variant grid -->
                <div class="flex min-h-0 flex-1 flex-col overflow-y-auto rounded-lg border border-black/40 bg-black/10 p-3">
                    <div v-if="detail.decks.length === 0" class="flex h-full items-center justify-center text-sm text-muted-foreground">
                        No variants yet. Use the form to add one.
                    </div>
                    <div v-else class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                        <ArchetypeDeckCard
                            v-for="(deck, idx) in detail.decks"
                            :key="deck.id"
                            :archetype-id="detail.archetype.id"
                            :deck="deck"
                            :index="idx"
                            :show-most-played="detail.decks.length > 1 && idx === 0"
                            deletable
                            :can-delete="canDeleteVariant"
                            @card-enter="onCardEnter"
                            @card-leave="onCardLeave"
                            @delete="requestDelete"
                        />
                    </div>
                </div>
            </div>

            <!-- Hover preview -->
            <Transition name="fade">
                <div
                    v-if="hoveredCard?.image"
                    class="pointer-events-none absolute z-50"
                    :style="{ top: `${previewTop}px`, left: `${previewLeft}px` }"
                >
                    <img
                        :src="hoveredCard.image"
                        :alt="hoveredCard.name"
                        class="w-[200px] rounded-lg shadow-xl ring-1 ring-border"
                    />
                </div>
            </Transition>

            <!-- Delete confirmation -->
            <div
                v-if="confirmingDeleteDeckId !== null"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
                @click.self="confirmingDeleteDeckId = null"
            >
                <div class="w-80 rounded-lg border border-black/40 bg-background p-6 shadow-lg">
                    <h3 class="text-sm font-semibold text-foreground">Remove Variant</h3>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Matches that reference this variant will lose their variant attribution but stay linked to the archetype.
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <Button variant="outline" size="sm" @click="confirmingDeleteDeckId = null">Cancel</Button>
                        <Button variant="destructive" size="sm" @click="confirmDelete">Remove</Button>
                    </div>
                </div>
            </div>
        </div>
    </ArchetypeLayout>
</template>

<style scoped>
.fade-enter-active { transition: opacity 0.1s ease; }
.fade-leave-active { transition: opacity 0.05s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
