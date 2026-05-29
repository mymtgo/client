<script setup lang="ts">
import LeaguesStoreController from '@/actions/App/Http/Controllers/Leagues/StoreController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ManualLeagueDeckOption } from '@/types/leagues';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ decks: ManualLeagueDeckOption[] }>();

const open = ref(false);
const nameTouched = ref(false);

function defaultStartedAt(): string {
    const now = new Date();
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

const form = useForm({
    deck_id: null as number | null,
    started_at: defaultStartedAt(),
    name: '',
});

const selectedDeck = computed(() => props.decks.find((d) => d.id === form.deck_id) ?? null);
const formatLabel = computed(() => selectedDeck.value?.format ?? '');

function formatNameDefault(): string {
    if (!selectedDeck.value || !form.started_at) return '';
    const dt = new Date(form.started_at);
    const pad = (n: number) => n.toString().padStart(2, '0');
    const date = `${pad(dt.getDate())}-${pad(dt.getMonth() + 1)}-${dt.getFullYear()}`;
    let hours = dt.getHours();
    const minutes = pad(dt.getMinutes());
    const ampm = hours >= 12 ? 'pm' : 'am';
    hours = hours % 12 || 12;
    return `${formatLabel.value} League ${date} ${pad(hours)}:${minutes}${ampm}`;
}

watch([() => form.deck_id, () => form.started_at], () => {
    if (!nameTouched.value) {
        form.name = formatNameDefault();
    }
});

function openDialog() {
    form.reset();
    form.started_at = defaultStartedAt();
    nameTouched.value = false;
    open.value = true;
}

function onNameInput(value: string) {
    nameTouched.value = true;
    form.name = value;
}

function onDeckChange(value: unknown) {
    if (value === '' || value === null || value === undefined) {
        form.deck_id = null;
        return;
    }
    const parsed = Number(value);
    form.deck_id = Number.isFinite(parsed) ? parsed : null;
}

function submit() {
    form.submit(LeaguesStoreController(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

defineExpose({ open: openDialog });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Create manual league</DialogTitle>
                <DialogDescription>Build a league out of imported or unlinked matches.</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="deck">Deck</Label>
                    <Select
                        :model-value="form.deck_id !== null ? String(form.deck_id) : ''"
                        @update:model-value="onDeckChange"
                    >
                        <SelectTrigger id="deck">
                            <SelectValue placeholder="Select a deck" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="d in decks" :key="d.id" :value="String(d.id)">
                                {{ d.name }} <span class="text-xs text-muted-foreground">— {{ d.format }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.deck_id" class="text-xs text-destructive">{{ form.errors.deck_id }}</p>
                    <p v-if="formatLabel" class="text-xs text-muted-foreground">
                        Format: <span class="font-medium text-foreground">{{ formatLabel }}</span>
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="started_at">Started at</Label>
                    <Input id="started_at" v-model="form.started_at" type="datetime-local" />
                    <p v-if="form.errors.started_at" class="text-xs text-destructive">{{ form.errors.started_at }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="name">Name</Label>
                    <Input id="name" :model-value="form.name" @update:model-value="onNameInput" />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="form.processing || !form.deck_id">Create league</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
