<script setup lang="ts">
import IndexController from '@/actions/App/Http/Controllers/Archetypes/IndexController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import DekUpload from '@/pages/archetypes/partials/DekUpload.vue';
import ManaIdentityPicker from '@/pages/archetypes/partials/ManaIdentityPicker.vue';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    name?: string;
    format?: string;
    colorIdentity?: string | null;
    initialCards?: App.Data.Front.CardData[] | null;
    submitLabel: string;
}>();

const emit = defineEmits<{
    submit: [data: { name: string; format: string; color_identity: string | null; cards: any[] }];
}>();

const FORMATS = [
    { value: 'modern', label: 'Modern' },
    { value: 'pauper', label: 'Pauper' },
    { value: 'legacy', label: 'Legacy' },
    { value: 'vintage', label: 'Vintage' },
    { value: 'premodern', label: 'Premodern' },
];

const name = ref(props.name ?? '');
const format = ref(props.format ?? '');
const colorIdentity = ref<string | null>(props.colorIdentity ?? null);
const resolvedCards = ref<any[] | null>(props.initialCards ?? null);
const submitting = ref(false);

function onDekResolved(data: { cards: any[]; color_identity: string | null }) {
    resolvedCards.value = data.cards;
    if (data.color_identity) {
        colorIdentity.value = data.color_identity;
    }
}

function handleSubmit() {
    if (!resolvedCards.value?.length) return;
    submitting.value = true;
    emit('submit', {
        name: name.value,
        format: format.value,
        color_identity: colorIdentity.value,
        cards: resolvedCards.value,
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

            <div class="flex gap-3 pt-4">
                <Button type="submit" :disabled="submitting || !resolvedCards?.length || !name || !format">
                    {{ submitLabel }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="IndexController.url()">Cancel</Link>
                </Button>
            </div>
        </div>

        <!-- Right column: deck upload/preview -->
        <div class="flex min-h-0 flex-1 flex-col rounded-lg border border-black/40 bg-black/10">
            <DekUpload :initial-cards="initialCards" @resolved="onDekResolved" />
        </div>
    </form>
</template>
