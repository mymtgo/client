<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import ManaSymbols from '@/components/ManaSymbols.vue';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';

defineProps<{
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const open = ref(false);
const matchId = ref<number | null>(null);
const matchFormat = ref<string | null>(null);
const search = ref('');

const formatMap: Record<string, string> = {
    CMODERN: 'modern',
    CPAUPER: 'pauper',
    CLEGACY: 'legacy',
    CVINTAGE: 'vintage',
    CPREMODERN: 'premodern',
};

const filteredArchetypes = computed(() => {
    return (archetypes: App.Data.Front.ArchetypeData[]) => {
        let filtered = archetypes;

        if (matchFormat.value) {
            const mapped = formatMap[matchFormat.value] ?? matchFormat.value.toLowerCase();
            filtered = filtered.filter((a) => a.format === mapped);
        }

        if (search.value) {
            const q = search.value.toLowerCase();
            filtered = filtered.filter((a) => a.name.toLowerCase().includes(q));
        }

        return filtered;
    };
});

const form = useForm<{ archetype_id: number | null }>({
    archetype_id: null,
});

const selectArchetype = (archetypeId: number) => {
    if (!matchId.value) return;

    form.archetype_id = archetypeId;
    form.submit(UpdateArchetypeController({ id: matchId.value }), {
        onSuccess: () => {
            open.value = false;
            form.reset();
            search.value = '';
        },
    });
};

const openForMatch = (id: number, format: string | null) => {
    matchId.value = id;
    matchFormat.value = format;
    search.value = '';
    open.value = true;
};

defineExpose({ openForMatch });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[80vh] flex flex-col">
            <DialogHeader>
                <DialogTitle>Set Archetype</DialogTitle>
                <DialogDescription>Search and select an archetype for this opponent.</DialogDescription>
            </DialogHeader>

            <Input v-model="search" placeholder="Search archetypes..." class="mb-2" />

            <div class="flex-1 overflow-y-auto space-y-0.5">
                <Button
                    v-for="archetype in filteredArchetypes(archetypes)"
                    :key="archetype.id"
                    variant="ghost"
                    class="w-full justify-between"
                    :disabled="form.processing"
                    @click="selectArchetype(archetype.id)"
                >
                    <span class="flex-1 text-left">{{ archetype.name }}</span>
                    <ManaSymbols :symbols="archetype.colorIdentity" />
                </Button>

                <p v-if="filteredArchetypes(archetypes).length === 0" class="py-4 text-center text-sm text-muted-foreground">
                    No archetypes found.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
