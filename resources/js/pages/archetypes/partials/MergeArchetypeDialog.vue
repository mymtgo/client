<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import MergeController from '@/actions/App/Http/Controllers/Archetypes/MergeController';
import MergeCandidatesController from '@/actions/App/Http/Controllers/Archetypes/MergeCandidatesController';

interface Props {
    archetype: App.Data.Front.ArchetypeData;
    open: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    merged: [];
}>();

const candidates = ref<App.Data.Front.ArchetypeData[]>([]);
const selected = ref<App.Data.Front.ArchetypeData | null>(null);
const loading = ref(false);
const search = ref('');
const submitting = ref(false);

watch(
    () => props.open,
    async (next) => {
        if (!next) {
            selected.value = null;
            search.value = '';
            return;
        }

        loading.value = true;
        try {
            const response = await fetch(
                MergeCandidatesController.url({ archetype: props.archetype.id }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            candidates.value = (await response.json()) as App.Data.Front.ArchetypeData[];
        } finally {
            loading.value = false;
        }
    },
);

const filteredCandidates = computed<App.Data.Front.ArchetypeData[]>(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') {
        return candidates.value;
    }
    return candidates.value.filter((candidate) =>
        candidate.name.toLowerCase().includes(term),
    );
});

function submit(): void {
    if (selected.value === null) {
        return;
    }
    submitting.value = true;
    router.post(
        MergeController.url({ archetype: props.archetype.id }),
        { parent_id: selected.value.id },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('merged');
                emit('update:open', false);
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Merge {{ props.archetype.name }}</DialogTitle>
                <DialogDescription>
                    Pick the archetype to merge this one into. Future detections of
                    {{ props.archetype.name }} will be attributed to the parent.
                    Existing matches are not changed.
                </DialogDescription>
            </DialogHeader>

            <Input v-model="search" placeholder="Search archetypes" class="mb-2" />

            <div class="max-h-72 overflow-y-auto rounded border">
                <p v-if="loading" class="p-3 text-sm text-muted-foreground">Loading…</p>
                <p
                    v-else-if="filteredCandidates.length === 0"
                    class="p-3 text-sm text-muted-foreground"
                >
                    No candidates available.
                </p>
                <button
                    v-for="candidate in filteredCandidates"
                    :key="candidate.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 border-b px-3 py-2 text-left text-sm last:border-b-0 hover:bg-muted"
                    :class="{ 'bg-muted': selected?.id === candidate.id }"
                    @click="selected = candidate"
                >
                    <span>{{ candidate.name }}</span>
                    <span class="text-xs uppercase text-muted-foreground">
                        {{ candidate.format }}
                    </span>
                </button>
            </div>

            <p v-if="selected" class="mt-2 text-sm text-muted-foreground">
                Future detections of <strong>{{ props.archetype.name }}</strong> will be
                attributed to <strong>{{ selected.name }}</strong>. You can unmerge
                later.
            </p>

            <DialogFooter>
                <Button variant="outline" @click="close">Cancel</Button>
                <Button :disabled="selected === null || submitting" @click="submit">
                    Merge
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
