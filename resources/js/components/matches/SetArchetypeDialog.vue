<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import ArchetypePicker from '@/components/archetypes/ArchetypePicker.vue';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import BulkUpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/BulkUpdateArchetypeController';

const emit = defineEmits<{
    archetypeSet: [];
}>();

defineProps<{
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const open = ref(false);
const matchId = ref<number | null>(null);
const matchIds = ref<number[]>([]);
const matchFormat = ref<string | null>(null);

const isBulkMode = computed(() => matchIds.value.length > 0);

const singleForm = useForm<{ archetype_id: number | null }>({
    archetype_id: null,
});

const bulkForm = useForm<{ match_ids: number[]; archetype_id: number | null }>({
    match_ids: [],
    archetype_id: null,
});

const selectArchetype = (archetypeId: number) => {
    if (isBulkMode.value) {
        bulkForm.match_ids = matchIds.value;
        bulkForm.archetype_id = archetypeId;
        bulkForm.submit(BulkUpdateArchetypeController(), {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                bulkForm.reset();
                emit('archetypeSet');
            },
        });
    } else {
        if (!matchId.value) return;

        singleForm.archetype_id = archetypeId;
        singleForm.submit(UpdateArchetypeController({ id: matchId.value }), {
            onSuccess: () => {
                open.value = false;
                singleForm.reset();
            },
        });
    }
};

const openForMatch = (id: number, format: string | null) => {
    matchId.value = id;
    matchIds.value = [];
    matchFormat.value = format;
    open.value = true;
};

const openForMatches = (ids: number[], format: string | null) => {
    matchId.value = null;
    matchIds.value = ids;
    matchFormat.value = format;
    open.value = true;
};

defineExpose({ openForMatch, openForMatches });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[80vh] flex flex-col">
            <DialogHeader>
                <DialogTitle>Set Archetype</DialogTitle>
                <DialogDescription>
                    <template v-if="isBulkMode">
                        Set archetype for {{ matchIds.length }} selected {{ matchIds.length === 1 ? 'match' : 'matches' }}.
                    </template>
                    <template v-else>
                        Search and select an archetype for this opponent.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <ArchetypePicker
                :archetypes="archetypes"
                :format="matchFormat"
                show-fallbacks
                :disabled="singleForm.processing || bulkForm.processing"
                list-class="max-h-none flex-1 border-0 p-0"
                autofocus
                @select="selectArchetype"
            />
        </DialogContent>
    </Dialog>
</template>
