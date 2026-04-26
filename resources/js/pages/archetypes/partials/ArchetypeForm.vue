<script setup lang="ts">
import ScanMatchController from '@/actions/App/Http/Controllers/Archetypes/ScanMatchController';
import IndexController from '@/actions/App/Http/Controllers/Archetypes/IndexController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ArchetypePreview from '@/pages/archetypes/partials/ArchetypePreview.vue';
import DekUploadButton from '@/pages/archetypes/partials/DekUploadButton.vue';
import ManaIdentityPicker from '@/pages/archetypes/partials/ManaIdentityPicker.vue';
import MatchSelect from '@/pages/archetypes/partials/MatchSelect.vue';
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface MatchOption {
    id: number;
    opponent_username: string;
    started_at: string | null;
}

interface Prefill {
    source_match_id: number;
    format: string;
    color_identity: string | null;
    cards: any[];
}

const props = defineProps<{
    name?: string;
    format?: string;
    colorIdentity?: string | null;
    initialCards?: App.Data.Front.CardData[] | null;
    submitLabel: string;
    matches?: MatchOption[];
    prefill?: Prefill | null;
}>();

const emit = defineEmits<{
    submit: [data: { name: string; format: string; color_identity: string | null; cards: any[]; source_match_id: number | null; incomplete: boolean }];
}>();

const FORMATS = [
    { value: 'modern', label: 'Modern' },
    { value: 'pauper', label: 'Pauper' },
    { value: 'legacy', label: 'Legacy' },
    { value: 'vintage', label: 'Vintage' },
    { value: 'premodern', label: 'Premodern' },
];

const name = ref(props.name ?? '');
const format = ref(props.prefill?.format ?? props.format ?? '');
const colorIdentity = ref<string | null>(props.prefill?.color_identity ?? props.colorIdentity ?? null);
const resolvedCards = ref<any[] | null>(props.prefill?.cards ?? props.initialCards ?? null);
const sourceMatchId = ref<number | null>(props.prefill?.source_match_id ?? null);
const incomplete = ref(props.prefill !== null && props.prefill !== undefined);
const scanning = ref(false);
const scanError = ref<string | null>(null);
const submitting = ref(false);
const skipNextScan = ref(props.prefill !== null && props.prefill !== undefined);

function applyResolved(data: { cards: any[]; color_identity: string | null }) {
    resolvedCards.value = data.cards;
    if (data.color_identity) {
        colorIdentity.value = data.color_identity;
    }
}

function onDekResolved(data: { cards: any[]; color_identity: string | null }) {
    sourceMatchId.value = null;
    incomplete.value = false;
    scanError.value = null;
    applyResolved(data);
}

watch(sourceMatchId, async (matchId) => {
    if (matchId === null) {
        return;
    }

    if (skipNextScan.value) {
        skipNextScan.value = false;
        return;
    }

    scanning.value = true;
    scanError.value = null;

    try {
        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        const response = await fetch(ScanMatchController.url({ match: matchId }), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            throw new Error(data?.message ?? 'Failed to scan match.');
        }

        const data = await response.json();
        incomplete.value = true;
        applyResolved(data);
    } catch (e: any) {
        scanError.value = e.message ?? 'An error occurred.';
        sourceMatchId.value = null;
    } finally {
        scanning.value = false;
    }
});

function handleSubmit() {
    if (!resolvedCards.value?.length) return;
    submitting.value = true;
    emit('submit', {
        name: name.value,
        format: format.value,
        color_identity: colorIdentity.value,
        cards: resolvedCards.value,
        source_match_id: sourceMatchId.value,
        incomplete: incomplete.value,
    });
}
</script>

<template>
    <form class="flex min-h-0 flex-1 gap-8" @submit.prevent="handleSubmit">
        <!-- Left column: inputs -->
        <div class="flex w-72 shrink-0 flex-col gap-5">
            <div>
                <Label for="name">Archetype Name</Label>
                <Input id="name" v-model="name" placeholder="e.g. Mono Red Aggro" class="mt-1.5" required />
            </div>

            <div>
                <Label for="format">Format</Label>
                <Select v-model="format" required>
                    <SelectTrigger class="mt-1.5">
                        <SelectValue placeholder="Select format..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="f in FORMATS" :key="f.value" :value="f.value">
                            {{ f.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div>
                <Label>Color Identity</Label>
                <ManaIdentityPicker v-model="colorIdentity" class="mt-2" />
                <p class="mt-1.5 text-xs text-muted-foreground">Auto-populated from deck. Click to toggle.</p>
            </div>

            <div>
                <Label>Source</Label>
                <div class="mt-1.5 space-y-3">
                    <DekUploadButton @resolved="onDekResolved" />

                    <div class="relative flex items-center gap-2">
                        <div class="h-px flex-1 bg-border"></div>
                        <span class="text-xs text-muted-foreground">or</span>
                        <div class="h-px flex-1 bg-border"></div>
                    </div>

                    <MatchSelect
                        v-model="sourceMatchId"
                        :matches="matches ?? []"
                        :format="format"
                    />
                    <p v-if="scanning" class="text-xs text-muted-foreground">Scanning match...</p>
                    <p v-if="scanError" class="text-xs text-red-400">{{ scanError }}</p>
                </div>
            </div>

            <div class="flex gap-3 pt-4">
                <Button type="submit" :disabled="submitting || scanning || !resolvedCards?.length || !name || !format">
                    {{ submitLabel }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="IndexController.url()">Cancel</Link>
                </Button>
            </div>
        </div>

        <!-- Right column: preview -->
        <div class="flex min-h-0 flex-1 flex-col rounded-lg border border-black/40 bg-black/10">
            <ArchetypePreview :cards="resolvedCards" :incomplete="incomplete" />
        </div>
    </form>
</template>
