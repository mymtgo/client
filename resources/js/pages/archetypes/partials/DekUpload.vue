<script setup lang="ts">
import UploadDekController from '@/actions/App/Http/Controllers/Archetypes/UploadDekController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import { RotateCcw, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const emit = defineEmits<{
    resolved: [data: { cards: any[]; color_identity: string | null }];
}>();

const props = defineProps<{
    initialCards?: App.Data.Front.CardData[] | null;
}>();

const uploading = ref(false);
const error = ref<string | null>(null);
const cards = ref<any[] | null>(props.initialCards ?? null);
const showUpload = ref(!props.initialCards);

const maindeck = computed(() => {
    if (!cards.value) return {};
    const grouped: Record<string, any[]> = {};
    for (const card of cards.value.filter((c: any) => !c.sideboard)) {
        const type = card.type ?? 'Unknown';
        (grouped[type] ??= []).push(card);
    }
    return grouped;
});

const sideboard = computed(() => {
    if (!cards.value) return [];
    return cards.value.filter((c: any) => c.sideboard);
});

const maindeckCount = computed(
    () => cards.value?.filter((c: any) => !c.sideboard).reduce((sum: number, c: any) => sum + c.quantity, 0) ?? 0,
);
const sideboardCount = computed(
    () => cards.value?.filter((c: any) => c.sideboard).reduce((sum: number, c: any) => sum + c.quantity, 0) ?? 0,
);

async function handleUpload(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    uploading.value = true;
    error.value = null;

    try {
        const formData = new FormData();
        formData.append('dek_file', file);

        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        const response = await fetch(UploadDekController.url(), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
            },
            body: formData,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            throw new Error(data?.message ?? 'Failed to parse deck file.');
        }

        const data = await response.json();
        cards.value = data.cards;
        showUpload.value = false;
        emit('resolved', data);
    } catch (e: any) {
        error.value = e.message ?? 'An error occurred.';
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

function reupload() {
    showUpload.value = true;
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- Upload zone -->
        <div v-if="showUpload" class="flex flex-1 items-center justify-center">
            <label
                class="flex w-4/5 cursor-pointer flex-col items-center rounded-lg border-2 border-dashed border-white/15 px-8 py-10 text-center transition-colors hover:border-white/30"
            >
                <Upload v-if="!uploading" class="mb-2 size-8 text-muted-foreground" />
                <Spinner v-else class="mb-2 size-8" />
                <span class="text-sm text-muted-foreground">
                    {{ uploading ? 'Parsing deck...' : 'Click to upload or drag & drop' }}
                </span>
                <span class="mt-1 text-xs text-muted-foreground/60">.dek files only</span>
                <input type="file" accept=".dek" class="hidden" :disabled="uploading" @change="handleUpload" />
            </label>
        </div>

        <!-- Card preview -->
        <template v-else-if="cards">
            <div class="flex items-center justify-between border-b border-black/40 px-4 py-2.5">
                <span class="text-sm text-muted-foreground">
                    {{ maindeckCount }} cards + {{ sideboardCount }} sideboard
                </span>
                <Button variant="ghost" size="sm" class="text-purple-400 hover:text-purple-300" @click="reupload">
                    <RotateCcw class="mr-1.5 size-3.5" />
                    Re-upload
                </Button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <DeckList :maindeck="maindeck" :sideboard="sideboard" />
            </div>
        </template>

        <!-- Error -->
        <div v-if="error" class="px-4 py-2">
            <p class="text-sm text-red-400">{{ error }}</p>
        </div>
    </div>
</template>
