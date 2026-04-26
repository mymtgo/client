<script setup lang="ts">
import { ref, computed, toRef } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import ManaSymbols from '@/components/ManaSymbols.vue';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import BulkUpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/BulkUpdateArchetypeController';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';

const emit = defineEmits<{
    archetypeSet: [];
}>();

const props = defineProps<{
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const open = ref(false);
const matchId = ref<number | null>(null);
const matchIds = ref<number[]>([]);
const matchFormat = ref<string | null>(null);
const search = ref('');

const isBulkMode = computed(() => matchIds.value.length > 0);

const { fallbacks, regular } = useArchetypeSplit(toRef(props, 'archetypes'), matchFormat, search);

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
                search.value = '';
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
                search.value = '';
            },
        });
    }
};

const openForMatch = (id: number, format: string | null) => {
    matchId.value = id;
    matchIds.value = [];
    matchFormat.value = format;
    search.value = '';
    open.value = true;
};

const openForMatches = (ids: number[], format: string | null) => {
    matchId.value = null;
    matchIds.value = ids;
    matchFormat.value = format;
    search.value = '';
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

            <Input v-model="search" placeholder="Search archetypes..." class="mb-2" />

            <div class="flex-1 overflow-y-auto space-y-0.5">
                <template v-if="fallbacks.length">
                    <Button
                        v-for="archetype in fallbacks"
                        :key="archetype.id"
                        variant="ghost"
                        class="w-full justify-between italic text-muted-foreground"
                        :disabled="singleForm.processing || bulkForm.processing"
                        @click="selectArchetype(archetype.id)"
                    >
                        <span class="flex-1 text-left">{{ archetype.name }}</span>
                        <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wide">System</span>
                    </Button>
                    <div class="my-1 border-t border-border" />
                </template>

                <Button
                    v-for="archetype in regular"
                    :key="archetype.id"
                    variant="ghost"
                    class="w-full justify-between"
                    :disabled="singleForm.processing || bulkForm.processing"
                    @click="selectArchetype(archetype.id)"
                >
                    <span class="flex-1 text-left">{{ archetype.name }}</span>
                    <ManaSymbols :symbols="archetype.colorIdentity" />
                </Button>

                <p
                    v-if="fallbacks.length === 0 && regular.length === 0"
                    class="py-4 text-center text-sm text-muted-foreground"
                >
                    No archetypes found.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
